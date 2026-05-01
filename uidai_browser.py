"""
UIDAI Aadhaar browser automation using Playwright.
Handles: page load → fill mobile+name → captcha fetch → OTP trigger → OTP submit → PDF download.
"""

import asyncio
import base64
import os
import tempfile
import logging
from pathlib import Path
from playwright.async_api import async_playwright, Page, Browser, BrowserContext, TimeoutError as PWTimeout

logger = logging.getLogger(__name__)

UIDAI_URL = "https://myaadhaar.uidai.gov.in/retrieve-eid-uid"

# Field selectors (update if UIDAI changes their DOM)
SEL_MOBILE    = 'input[formcontrolname="mobileNumber"], input[placeholder*="Mobile"], input[name*="mobile"]'
SEL_FULLNAME  = 'input[formcontrolname="fullName"], input[placeholder*="Name"], input[name*="name"]'
SEL_CAPTCHA_IMG = 'img[src*="captcha"], img.captcha-img, #captchaImage, img[alt*="captcha" i]'
SEL_CAPTCHA_INPUT = 'input[formcontrolname="captchaValue"], input[placeholder*="captcha" i], input[name*="captcha"]'
SEL_SEND_OTP  = 'button[type="submit"], button.sendOtp, button:has-text("Send OTP"), button:has-text("Get OTP")'
SEL_OTP_INPUT = 'input[formcontrolname="otp"], input[placeholder*="OTP"], input[name*="otp"], input[maxlength="6"]'
SEL_VERIFY    = 'button:has-text("Verify"), button:has-text("Submit"), button[type="submit"]'
SEL_DOWNLOAD  = 'a[href*=".pdf"], a[download], button:has-text("Download")'


class UIDaiSession:
    def __init__(self):
        self._pw = None
        self._browser: Browser | None = None
        self._context: BrowserContext | None = None
        self.page: Page | None = None
        self.temp_dir = tempfile.mkdtemp(prefix="aadhaar_")

    async def start(self, headless: bool = True):
        self._pw = await async_playwright().start()
        self._browser = await self._pw.chromium.launch(
            headless=headless,
            args=["--disable-blink-features=AutomationControlled"],
        )
        self._context = await self._browser.new_context(
            user_agent=(
                "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                "AppleWebKit/537.36 (KHTML, like Gecko) "
                "Chrome/124.0.0.0 Safari/537.36"
            ),
            viewport={"width": 1280, "height": 800},
            accept_downloads=True,
        )
        # Mask webdriver flag
        await self._context.add_init_script(
            "Object.defineProperty(navigator, 'webdriver', {get: () => undefined})"
        )
        self.page = await self._context.new_page()

    async def close(self):
        try:
            if self.page:
                await self.page.close()
            if self._context:
                await self._context.close()
            if self._browser:
                await self._browser.close()
            if self._pw:
                await self._pw.stop()
        except Exception:
            pass

    # ── Step 1: Navigate and fill mobile + name ──────────────────
    async def navigate_and_fill(self, mobile: str, fullname: str) -> dict:
        """
        Navigate to UIDAI retrieve page, fill mobile & name.
        Returns: {'ok': True, 'captcha_image': <base64 PNG bytes>}
                 {'ok': False, 'error': str}
        """
        try:
            await self.page.goto(UIDAI_URL, wait_until="networkidle", timeout=30_000)
            await asyncio.sleep(1)

            # Fill mobile number
            await self.page.wait_for_selector(SEL_MOBILE, timeout=15_000)
            await self.page.fill(SEL_MOBILE, "")
            await self.page.type(SEL_MOBILE, mobile, delay=60)

            # Fill full name
            try:
                await self.page.wait_for_selector(SEL_FULLNAME, timeout=8_000)
                await self.page.fill(SEL_FULLNAME, "")
                await self.page.type(SEL_FULLNAME, fullname, delay=60)
            except PWTimeout:
                logger.warning("Full name field not found — skipping")

            # Grab captcha image as base64
            captcha_bytes = await self._get_captcha_bytes()
            if not captcha_bytes:
                return {"ok": False, "error": "Captcha image nahi mili page pe."}

            return {"ok": True, "captcha_image": captcha_bytes}

        except PWTimeout as e:
            return {"ok": False, "error": f"Page load timeout: {e}"}
        except Exception as e:
            logger.exception("navigate_and_fill error")
            return {"ok": False, "error": str(e)}

    async def _get_captcha_bytes(self) -> bytes | None:
        """Return captcha image as raw PNG bytes (screenshot of element)."""
        try:
            el = await self.page.wait_for_selector(SEL_CAPTCHA_IMG, timeout=10_000)
            if el:
                return await el.screenshot(type="png")
        except PWTimeout:
            pass
        # Fallback: try src attribute (base64 data URI)
        try:
            src = await self.page.get_attribute(SEL_CAPTCHA_IMG, "src")
            if src and src.startswith("data:image"):
                b64 = src.split(",", 1)[1]
                return base64.b64decode(b64)
        except Exception:
            pass
        return None

    async def refresh_captcha(self) -> dict:
        """Click refresh/reload captcha and return new captcha image bytes."""
        try:
            refresh = await self.page.query_selector(
                'button[aria-label*="refresh" i], button:has-text("Refresh"), '
                'span.refresh-captcha, img[alt*="refresh" i], .captcha-refresh'
            )
            if refresh:
                await refresh.click()
                await asyncio.sleep(1)
            captcha_bytes = await self._get_captcha_bytes()
            if captcha_bytes:
                return {"ok": True, "captcha_image": captcha_bytes}
            return {"ok": False, "error": "Captcha refresh ke baad image nahi mili."}
        except Exception as e:
            return {"ok": False, "error": str(e)}

    # ── Step 2: Submit captcha → trigger OTP ─────────────────────
    async def submit_captcha_and_send_otp(self, captcha_text: str) -> dict:
        """
        Fill captcha value and click Send OTP.
        Returns {'ok': True} or {'ok': False, 'error': str}
        """
        try:
            await self.page.wait_for_selector(SEL_CAPTCHA_INPUT, timeout=8_000)
            await self.page.fill(SEL_CAPTCHA_INPUT, "")
            await self.page.type(SEL_CAPTCHA_INPUT, captcha_text, delay=50)

            btn = await self.page.wait_for_selector(SEL_SEND_OTP, timeout=8_000)
            await btn.click()
            await asyncio.sleep(2)

            # Check for error messages
            err = await self._page_error()
            if err:
                return {"ok": False, "error": err}

            return {"ok": True}

        except PWTimeout as e:
            return {"ok": False, "error": f"Send OTP button timeout: {e}"}
        except Exception as e:
            logger.exception("submit_captcha error")
            return {"ok": False, "error": str(e)}

    # ── Step 3: Submit OTP → download PDF ────────────────────────
    async def submit_otp_and_download(self, otp: str) -> dict:
        """
        Enter OTP, click verify/submit, wait for download.
        Returns {'ok': True, 'file_path': str} or {'ok': False, 'error': str}
        """
        try:
            await self.page.wait_for_selector(SEL_OTP_INPUT, timeout=15_000)
            await self.page.fill(SEL_OTP_INPUT, "")
            await self.page.type(SEL_OTP_INPUT, otp, delay=80)

            # Expect a download to start after clicking verify
            async with self.page.expect_download(timeout=30_000) as dl_info:
                verify_btn = await self.page.wait_for_selector(SEL_VERIFY, timeout=8_000)
                await verify_btn.click()

            download = await dl_info.value
            save_path = os.path.join(self.temp_dir, download.suggested_filename or "aadhaar.pdf")
            await download.save_as(save_path)

            if os.path.exists(save_path) and os.path.getsize(save_path) > 100:
                return {"ok": True, "file_path": save_path}

            # No direct download — look for a download link on resulting page
            await asyncio.sleep(2)
            err = await self._page_error()
            if err:
                return {"ok": False, "error": err}

            return {"ok": False, "error": "Download link nahi mila. OTP galat ho sakta hai."}

        except PWTimeout:
            # Maybe no download dialog — try clicking a download link
            try:
                await asyncio.sleep(2)
                err = await self._page_error()
                if err:
                    return {"ok": False, "error": err}
                dl_link = await self.page.wait_for_selector(SEL_DOWNLOAD, timeout=10_000)
                async with self.page.expect_download(timeout=30_000) as dl_info:
                    await dl_link.click()
                download = await dl_info.value
                save_path = os.path.join(self.temp_dir, download.suggested_filename or "aadhaar.pdf")
                await download.save_as(save_path)
                if os.path.exists(save_path):
                    return {"ok": True, "file_path": save_path}
            except Exception as e2:
                return {"ok": False, "error": f"Download fail: {e2}"}
        except Exception as e:
            logger.exception("submit_otp error")
            return {"ok": False, "error": str(e)}

        return {"ok": False, "error": "Unknown error during OTP submit."}

    async def _page_error(self) -> str | None:
        """Check page for visible error messages."""
        for sel in [
            '.error-msg', '.alert-danger', '.error', '[class*="error"]',
            'p.text-danger', '.invalid-feedback:visible', 'span.error',
        ]:
            try:
                el = await self.page.query_selector(sel)
                if el and await el.is_visible():
                    txt = (await el.inner_text()).strip()
                    if txt:
                        return txt
            except Exception:
                pass
        return None

    def cleanup(self):
        """Delete temp downloaded files."""
        import shutil
        try:
            shutil.rmtree(self.temp_dir, ignore_errors=True)
        except Exception:
            pass
