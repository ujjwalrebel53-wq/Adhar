"""
UIDAI Aadhaar retrieve — pure HTTP client (no browser needed).

Flow:
  1. GET  /retrieve-eid-uid          → grab CSRF token + session cookie
  2. GET  /getCaptcha                → captcha image bytes
  3. POST /sendOtp                   → submit name+mobile+captcha → OTP triggered
  4. POST /verifyOtp                 → submit OTP → UID/EID sent to mobile via SMS
  5. POST /downloadEaadhar (if avail)→ download e-Aadhaar PDF
"""

import asyncio
import logging
import os
import re
import tempfile
import time
import httpx
from proxy_helper import get_working_indian_proxy

logger = logging.getLogger(__name__)

BASE     = "https://myaadhaar.uidai.gov.in"
API_BASE = "https://myaadhaar.uidai.gov.in/api"   # Angular app's backend prefix — may vary

HEADERS_COMMON = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/124.0.0.0 Safari/537.36"
    ),
    "Accept-Language": "en-IN,en;q=0.9",
    "Referer": f"{BASE}/retrieve-eid-uid",
    "Origin":  BASE,
}

# ── Known UIDAI API endpoint patterns (reverse-engineered from XHR) ──
EP_CAPTCHA    = f"{BASE}/getCaptcha"
EP_SEND_OTP   = f"{BASE}/sendOtp"
EP_VERIFY_OTP = f"{BASE}/verifyOtp"
EP_DOWNLOAD   = f"{BASE}/downloadEaadhar"

# Alternate patterns used by some UIDAI sub-apps
EP_CAPTCHA_ALT    = f"{BASE}/generate-captcha"
EP_SEND_OTP_ALT   = f"{BASE}/send-otp"
EP_VERIFY_OTP_ALT = f"{BASE}/verify-otp"


class UIDaiSession:
    def __init__(self):
        self.client: httpx.AsyncClient | None = None
        self.temp_dir = tempfile.mkdtemp(prefix="aadhaar_")
        self._txn_id: str = ""
        self._mobile: str = ""
        self._fullname: str = ""
        self._captcha_token: str = ""

    async def start(self, headless: bool = True, proxy: str = ""):
        # Use manual proxy > env var > auto-fetch Indian proxy
        proxy_url = proxy or os.environ.get("UIDAI_PROXY", "")
        if not proxy_url:
            logger.info("Koi proxy set nahi — Indian proxy auto-fetch ho rahi hai...")
            found = await get_working_indian_proxy(max_test=50)
            if found:
                proxy_url = f"http://{found}"
                logger.info(f"Auto proxy use ho raha hai: {proxy_url}")
            else:
                logger.warning("Koi proxy nahi mila — direct connect try karega.")
        self._proxy_url = proxy_url
        self.client = httpx.AsyncClient(
            headers=HEADERS_COMMON,
            follow_redirects=True,
            timeout=40,
            verify=False,
            proxy=proxy_url if proxy_url else None,
        )

    async def close(self):
        if self.client:
            await self.client.aclose()

    def cleanup(self):
        import shutil
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    # ── Step 1: init session + fetch captcha ─────────────────────
    async def navigate_and_fill(self, mobile: str, fullname: str) -> dict:
        """
        1. Hit the UIDAI page to establish session cookie + CSRF token.
        2. Fetch captcha image.
        Returns {'ok': True, 'captcha_image': <bytes>}
        """
        self._mobile   = mobile
        self._fullname = fullname

        # Establish session — get cookies / XSRF token
        try:
            r = await self.client.get(f"{BASE}/retrieve-eid-uid")
            r.raise_for_status()
        except Exception as e:
            # Try rotating proxy once on failure
            logger.warning(f"Connection fail ({e}) — naya proxy try kar raha hoon...")
            found = await get_working_indian_proxy(max_test=60)
            if found:
                new_proxy = f"http://{found}"
                logger.info(f"Naya proxy: {new_proxy}")
                await self.client.aclose()
                self.client = httpx.AsyncClient(
                    headers=HEADERS_COMMON,
                    follow_redirects=True,
                    timeout=40,
                    verify=False,
                    proxy=new_proxy,
                )
                try:
                    r = await self.client.get(f"{BASE}/retrieve-eid-uid")
                    r.raise_for_status()
                except Exception as e2:
                    return {"ok": False, "error": f"Proxy se bhi UIDAI nahi khuli: {e2}"}
            else:
                return {"ok": False, "error": f"UIDAI site nahi khuli aur koi working proxy nahi mila. Server ka IP UIDAI ne block kar rakha hai."}

        # Extract XSRF / CSRF token from cookie or response body
        self._csrf = (
            self.client.cookies.get("XSRF-TOKEN")
            or self.client.cookies.get("csrftoken")
            or self.client.cookies.get("_csrf")
            or ""
        )
        if self._csrf:
            self.client.headers.update({
                "X-XSRF-TOKEN": self._csrf,
                "X-CSRF-Token": self._csrf,
            })

        # Fetch captcha
        return await self._fetch_captcha()

    async def _fetch_captcha(self) -> dict:
        """Try multiple known UIDAI captcha endpoints."""
        endpoints_to_try = [EP_CAPTCHA, EP_CAPTCHA_ALT]
        for ep in endpoints_to_try:
            try:
                params = {"ts": int(time.time() * 1000)}  # cache-bust
                r = await self.client.get(ep, params=params)
                ct = r.headers.get("content-type", "")
                if r.status_code == 200 and "image" in ct:
                    return {"ok": True, "captcha_image": r.content}
                # If JSON response with base64 image
                if r.status_code == 200 and "json" in ct:
                    data = r.json()
                    img_b64 = (
                        data.get("captchaImage") or data.get("image")
                        or data.get("captcha") or ""
                    )
                    if img_b64:
                        import base64
                        return {"ok": True, "captcha_image": base64.b64decode(img_b64)}
                    # Store token if returned
                    self._captcha_token = data.get("captchaToken") or data.get("token") or ""
            except Exception as e:
                logger.warning(f"Captcha endpoint {ep} failed: {e}")

        return {
            "ok": False,
            "error": (
                "UIDAI captcha fetch nahi hua.\n"
                "Possible reasons:\n"
                "• UIDAI ne API endpoint badal diya\n"
                "• Rate limiting / IP block\n\n"
                "Captcha image manually yahan se download karo:\n"
                f"{BASE}/retrieve-eid-uid\n"
                "aur bot ko sirf captcha text bhejo."
            ),
        }

    async def refresh_captcha(self) -> dict:
        return await self._fetch_captcha()

    # ── Step 2: submit captcha → send OTP ────────────────────────
    async def submit_captcha_and_send_otp(self, captcha_text: str) -> dict:
        """POST name + mobile + captcha to trigger OTP."""
        payload = {
            "fullName":     self._fullname,
            "mobileNumber": self._mobile,
            "captcha":      captcha_text,
            "captchaToken": self._captcha_token,
            "uidType":      "UID",   # or "EID"
        }
        endpoints_to_try = [EP_SEND_OTP, EP_SEND_OTP_ALT]
        for ep in endpoints_to_try:
            try:
                r = await self.client.post(ep, json=payload)
                data = self._parse_json_safe(r)
                logger.info(f"sendOtp [{ep}] → {r.status_code} | {data}")

                if r.status_code == 200 and data:
                    # Success indicators
                    if data.get("status") in ("y", "success", True, "true", "SUCCESS", "Y"):
                        self._txn_id = data.get("txnId") or data.get("txn") or ""
                        return {"ok": True}
                    # Error message from server
                    err = (
                        data.get("message") or data.get("error")
                        or data.get("errorMessage") or data.get("msg") or ""
                    )
                    if err:
                        return {"ok": False, "error": err}

            except Exception as e:
                logger.warning(f"sendOtp [{ep}] exception: {e}")

        return {
            "ok": False,
            "error": (
                "OTP send nahi hua. Possible reasons:\n"
                "• Captcha galat tha — /refresh karo\n"
                "• Mobile number Aadhaar se linked nahi\n"
                "• UIDAI rate limit / API change"
            ),
        }

    # ── Step 3: submit OTP → get result ──────────────────────────
    async def submit_otp_and_download(self, otp: str) -> dict:
        """POST OTP → UIDAI sends UID to SMS / or triggers PDF download."""
        payload = {
            "otp":    otp,
            "txnId":  self._txn_id,
            "uidType": "UID",
        }
        endpoints_to_try = [EP_VERIFY_OTP, EP_VERIFY_OTP_ALT]
        for ep in endpoints_to_try:
            try:
                r = await self.client.post(ep, json=payload)
                data = self._parse_json_safe(r)
                logger.info(f"verifyOtp [{ep}] → {r.status_code} | {data}")

                if r.status_code == 200 and data:
                    status = str(data.get("status", "")).lower()
                    if status in ("y", "success", "true"):
                        # Try to get downloadable PDF if available
                        pdf_result = await self._try_download_pdf(data)
                        if pdf_result["ok"]:
                            return pdf_result
                        # No PDF — UID sent via SMS
                        uid_masked = data.get("uid") or data.get("maskedUid") or ""
                        msg = data.get("message") or "UID/EID aapke registered mobile pe SMS mein bhej diya gaya."
                        return {"ok": True, "file_path": None, "message": msg, "uid": uid_masked}

                    err = (
                        data.get("message") or data.get("error")
                        or data.get("errorMessage") or "OTP galat ya expire ho gaya."
                    )
                    return {"ok": False, "error": err}

            except Exception as e:
                logger.warning(f"verifyOtp [{ep}] exception: {e}")

        return {"ok": False, "error": "OTP verify nahi hua. Dobara /fetch karo."}

    async def _try_download_pdf(self, verify_response: dict) -> dict:
        """After OTP verify, try to download e-Aadhaar PDF if endpoint available."""
        try:
            dl_token = (
                verify_response.get("downloadToken")
                or verify_response.get("token")
                or ""
            )
            params = {"token": dl_token} if dl_token else {}
            r = await self.client.get(EP_DOWNLOAD, params=params, timeout=40)
            ct = r.headers.get("content-type", "")
            if r.status_code == 200 and "pdf" in ct:
                path = os.path.join(self.temp_dir, "aadhaar.pdf")
                with open(path, "wb") as f:
                    f.write(r.content)
                return {"ok": True, "file_path": path, "message": ""}
        except Exception as e:
            logger.warning(f"PDF download attempt failed: {e}")
        return {"ok": False}

    @staticmethod
    def _parse_json_safe(r: httpx.Response) -> dict:
        try:
            return r.json()
        except Exception:
            return {}
