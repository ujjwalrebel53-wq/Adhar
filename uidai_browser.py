"""
UIDAI persistent browser session using Playwright.
Browser 24/7 open rehta hai — /fetch pe form fill + captcha reflect.
"""

import asyncio
import logging
import os
import tempfile
import time

from playwright.async_api import (
    async_playwright,
    Page,
    Browser,
    BrowserContext,
    Playwright,
    TimeoutError as PWTimeout,
)

logger = logging.getLogger(__name__)

UIDAI_URL = "https://myaadhaar.uidai.gov.in/retrieve-eid-uid"

# ── Selectors (Angular Material + UIDAI DOM) ─────────────────────────
SEL_UID_RADIO   = 'mat-radio-button[value="uid"], input[value="UID"], label:has-text("Aadhaar")'
SEL_NAME        = (
    'input[formcontrolname="fullName"], '
    'input[placeholder*="Name" i], '
    'input[id*="name" i], '
    'mat-form-field:has(label:has-text("Name" )) input'
)
SEL_MOBILE      = (
    'input[formcontrolname="mobileNumber"], '
    'input[placeholder*="Mobile" i], '
    'input[id*="mobile" i], '
    'input[type="tel"]'
)
SEL_DOB         = (
    'input[formcontrolname="dob"], '
    'input[placeholder*="Birth" i], '
    'input[id*="dob" i], '
    'input[type="date"]'
)
SEL_CAPTCHA_IMG = (
    'img[src*="captcha" i], '
    'img[alt*="captcha" i], '
    '#captchaImage, .captcha-img, '
    'img.captcha'
)
SEL_CAPTCHA_REFRESH = (
    '[aria-label*="refresh" i], '
    'button:has-text("Refresh"), '
    'img[src*="refresh" i], '
    '.refresh-captcha, '
    'span:has-text("↻")'
)
SEL_CAPTCHA_INPUT = (
    'input[formcontrolname="captchaValue"], '
    'input[placeholder*="Captcha" i], '
    'input[id*="captcha" i]'
)
SEL_SEND_OTP    = (
    'button:has-text("Send OTP"), '
    'button:has-text("Get OTP"), '
    'button[type="submit"]:visible'
)
SEL_OTP_INPUT   = (
    'input[formcontrolname="otp"], '
    'input[placeholder*="OTP" i], '
    'input[maxlength="6"], '
    'input[id*="otp" i]'
)
SEL_VERIFY_BTN  = (
    'button:has-text("Verify"), '
    'button:has-text("Submit"), '
    'button:has-text("Login"), '
    'button[type="submit"]:visible'
)
SEL_ERROR       = (
    '.error-msg:visible, .alert-danger:visible, '
    'mat-error:visible, .text-danger:visible, '
    '.invalid-feedback:visible, snack-bar-container:visible'
)


class UIDaiSession:
    """
    Persistent Playwright session.
    Browser starts once at bot startup and stays open.
    Each /fetch call reloads the UIDAI page and fills the form.
    """

    def __init__(self):
        self._pw: Playwright | None = None
        self._browser: Browser | None = None
        self._context: BrowserContext | None = None
        self.page: Page | None = None
        self.temp_dir = tempfile.mkdtemp(prefix="aadhaar_")
        self._lock = asyncio.Lock()  # one user at a time

    async def start(self, headless: bool = True):
        """Launch browser and open UIDAI page. Call once at startup."""
        self._pw = await async_playwright().start()
        self._browser = await self._pw.firefox.launch(
            headless=headless,
        )
        self._context = await self._browser.new_context(
            user_agent=(
                "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                "AppleWebKit/537.36 (KHTML, like Gecko) "
                "Chrome/124.0.0.0 Safari/537.36"
            ),
            viewport={"width": 1280, "height": 900},
            accept_downloads=True,
            locale="en-IN",
        )
        # Firefox mein webdriver flag automatically hidden hota hai
        self.page = await self._context.new_page()
        # Initial page load
        await self._load_uidai_page()
        logger.info("UIDAI browser session started and page loaded.")

    async def close(self):
        """Call only when shutting down the bot completely."""
        try:
            if self.page:        await self.page.close()
            if self._context:    await self._context.close()
            if self._browser:    await self._browser.close()
            if self._pw:         await self._pw.stop()
        except Exception:
            pass

    def cleanup(self):
        import shutil
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    # ── Internal: load/reload UIDAI page ────────────────────────
    async def _load_uidai_page(self):
        await self.page.goto(UIDAI_URL, wait_until="networkidle", timeout=40_000)
        await asyncio.sleep(1.5)
        # Select "Aadhaar / UID" radio if present
        try:
            rb = await self.page.wait_for_selector(SEL_UID_RADIO, timeout=5_000)
            if rb:
                await rb.click()
                await asyncio.sleep(0.5)
        except PWTimeout:
            pass  # Radio not present or already selected

    # ── Step 1: fill form + get captcha ──────────────────────────
    async def navigate_and_fill(self, mobile: str, fullname: str) -> dict:
        """
        Reload UIDAI page, fill Name + Mobile, skip DOB, return captcha image bytes.
        """
        async with self._lock:
            try:
                # Reload fresh page
                await self._load_uidai_page()

                # Fill Full Name
                await self._fill_field(SEL_NAME, fullname, label="Name")

                # Fill Mobile Number
                await self._fill_field(SEL_MOBILE, mobile, label="Mobile")

                # Skip DOB — check if field exists and leave it or fill dummy-bypass
                try:
                    dob_el = await self.page.wait_for_selector(SEL_DOB, timeout=3_000)
                    if dob_el and await dob_el.is_visible():
                        # Try to make it not required by clearing
                        await dob_el.evaluate("el => el.removeAttribute('required')")
                        logger.info("DOB field found — marked not required (bypassed)")
                except PWTimeout:
                    pass  # DOB field nahi hai — good

                await asyncio.sleep(0.8)

                # Get captcha image
                captcha_bytes = await self._screenshot_captcha()
                if not captcha_bytes:
                    return {"ok": False, "error": "Captcha image detect nahi hui page pe."}

                return {"ok": True, "captcha_image": captcha_bytes}

            except PWTimeout as e:
                return {"ok": False, "error": f"Page timeout: {e}"}
            except Exception as e:
                logger.exception("navigate_and_fill error")
                return {"ok": False, "error": str(e)}

    async def _fill_field(self, selector: str, value: str, label: str = "field"):
        """Wait for field, clear it, type value."""
        try:
            el = await self.page.wait_for_selector(selector, timeout=10_000)
            await el.click()
            # Select all + delete to clear
            await el.press("Control+a")
            await el.press("Delete")
            await asyncio.sleep(0.2)
            await el.type(value, delay=50)
            logger.info(f"Filled {label}: {value}")
        except PWTimeout:
            logger.warning(f"{label} field not found with selector: {selector}")

    async def _screenshot_captcha(self) -> bytes | None:
        """Take screenshot of captcha element."""
        try:
            el = await self.page.wait_for_selector(SEL_CAPTCHA_IMG, timeout=10_000)
            if el and await el.is_visible():
                return await el.screenshot(type="png")
        except PWTimeout:
            pass

        # Fallback: screenshot a bounding region
        try:
            box = await self.page.query_selector(".captcha-container, #captchaDiv, .captcha-wrapper")
            if box:
                return await box.screenshot(type="png")
        except Exception:
            pass

        # Last resort: full page screenshot cropped (send full page)
        logger.warning("Captcha element not found — sending full page screenshot")
        return await self.page.screenshot(type="png", full_page=False)

    # ── Refresh captcha ──────────────────────────────────────────
    async def refresh_captcha(self) -> dict:
        async with self._lock:
            try:
                try:
                    refresh_btn = await self.page.wait_for_selector(SEL_CAPTCHA_REFRESH, timeout=4_000)
                    if refresh_btn:
                        await refresh_btn.click()
                        await asyncio.sleep(1.2)
                except PWTimeout:
                    pass

                captcha_bytes = await self._screenshot_captcha()
                if captcha_bytes:
                    return {"ok": True, "captcha_image": captcha_bytes}
                return {"ok": False, "error": "Refresh ke baad captcha nahi mili."}
            except Exception as e:
                return {"ok": False, "error": str(e)}

    # ── Step 2: submit captcha → send OTP ────────────────────────
    async def submit_captcha_and_send_otp(self, captcha_text: str) -> dict:
        async with self._lock:
            try:
                # Fill captcha input
                await self._fill_field(SEL_CAPTCHA_INPUT, captcha_text, label="Captcha")
                await asyncio.sleep(0.4)

                # Click Send OTP
                btn = await self.page.wait_for_selector(SEL_SEND_OTP, timeout=8_000)
                await btn.click()
                await asyncio.sleep(2.5)

                # Check for error
                err = await self._get_page_error()
                if err:
                    # Refresh captcha for retry
                    new_cap = await self._screenshot_captcha()
                    return {"ok": False, "error": err, "captcha_image": new_cap}

                # Check if OTP field appeared (success indicator)
                try:
                    await self.page.wait_for_selector(SEL_OTP_INPUT, timeout=6_000)
                    return {"ok": True}
                except PWTimeout:
                    # No OTP field — maybe success without visible field
                    err2 = await self._get_page_error()
                    if err2:
                        new_cap = await self._screenshot_captcha()
                        return {"ok": False, "error": err2, "captcha_image": new_cap}
                    return {"ok": True}

            except PWTimeout as e:
                return {"ok": False, "error": f"Send OTP button timeout: {e}"}
            except Exception as e:
                logger.exception("submit_captcha error")
                return {"ok": False, "error": str(e)}

    # ── Step 3: submit OTP → download PDF ────────────────────────
    async def submit_otp_and_download(self, otp: str) -> dict:
        async with self._lock:
            try:
                await self._fill_field(SEL_OTP_INPUT, otp, label="OTP")
                await asyncio.sleep(0.4)

                # Try with download expectation
                try:
                    async with self.page.expect_download(timeout=35_000) as dl_info:
                        verify_btn = await self.page.wait_for_selector(SEL_VERIFY_BTN, timeout=8_000)
                        await verify_btn.click()
                    download = await dl_info.value
                    save_path = os.path.join(
                        self.temp_dir,
                        download.suggested_filename or "aadhaar.pdf"
                    )
                    await download.save_as(save_path)
                    if os.path.exists(save_path) and os.path.getsize(save_path) > 500:
                        return {"ok": True, "file_path": save_path, "message": ""}
                except PWTimeout:
                    pass

                await asyncio.sleep(2)

                # Check for error
                err = await self._get_page_error()
                if err:
                    return {"ok": False, "error": err}

                # Success — UID sent via SMS (no direct PDF)
                return {
                    "ok": True,
                    "file_path": None,
                    "message": "✅ OTP verified! UID/EID aapke registered mobile pe SMS mein bhej diya gaya.",
                }

            except Exception as e:
                logger.exception("submit_otp error")
                return {"ok": False, "error": str(e)}

    async def _get_page_error(self) -> str | None:
        try:
            el = await self.page.wait_for_selector(SEL_ERROR, timeout=2_000)
            if el:
                txt = (await el.inner_text()).strip()
                if txt and len(txt) > 2:
                    return txt
        except PWTimeout:
            pass
        return None
