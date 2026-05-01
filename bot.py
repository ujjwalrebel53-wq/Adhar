"""
Aadhaar Retrieve Bot — httpx + Telegram.
Settings live bot_config.json se load hoti hain (link_runner.php UI se edit karo).
"""

import asyncio
import json
import logging
import os
import re

from telegram import Update, Message, InputFile
from telegram.constants import ParseMode, ChatAction
from telegram.ext import (
    Application,
    CommandHandler,
    MessageHandler,
    ConversationHandler,
    ContextTypes,
    filters,
)

from uidai_api import UIDaiSession

LOG_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "adhar_logs.txt")

logging.basicConfig(
    format="%(asctime)s | %(levelname)s | %(name)s | %(message)s",
    level=logging.DEBUG,
    handlers=[
        logging.StreamHandler(),
        logging.FileHandler(LOG_FILE, encoding="utf-8"),
    ],
)
logger = logging.getLogger(__name__)

# ── Load config from bot_config.json (written by link_runner.php) ───
_CONFIG_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "bot_config.json")

_DEFAULTS = {
    "py_bot_token":     "8750675658:AAGj9h3DvN8KzSHviWh6g3hiaCkycrk1aoI",
    "py_uidai_proxy":   "",
    "py_fetch_cmd":     "/fetch",
    "py_cancel_cmd":    "/cancel",
    "py_refresh_cmd":   "/refresh",
    "py_start_msg":     "👾 <b>Aadhaar Retrieve Bot</b> — Online ✅\n\n/fetch &lt;mobile&gt; &lt;fullname&gt;",
    "py_loading_steps": "🔐 Secure tunnel initialize ho raha hai...\n🛰️ UIDAI node se connect ho raha hai...\n🧬 Session payload inject ho raha hai...\n🔍 Biometric endpoint resolve ho raha hai...\n⚡ Sandbox bypass ho raha hai...\n🗝️ Identity matrix decrypt ho rahi hai...\n📋 Form fill ho raha hai...\n📸 Captcha capture ho raha hai...",
    "py_otp_steps":     "🔐 OTP token validate ho raha hai...\n🧬 Biometric hash cross-reference ho raha hai...\n📂 Encrypted Aadhaar file locate ho rahi hai...\n⬇️ Document decrypt aur package ho raha hai...\n✅ Document secured. Bhej raha hoon...",
    "py_captcha_msg":   "📸 <b>Captcha ready hai!</b>\n\nNeeche captcha image dekho aur <b>text reply karo.</b>\n<i>/refresh = naya captcha | /cancel = band karo</i>",
    "py_otp_msg":       "📲 <b>OTP bheja gaya!</b>\n📱 <code>{mobile}</code> pe OTP aaya hoga.\n\n🔢 <b>OTP reply karo:</b>\n<i>/cancel = band karo</i>",
    "py_success_msg":   "✅ <b>Aadhaar document ready!</b>\n🔒 <i>Yeh file sirf aapke liye hai. Safely store karo.</i>",
    "py_cancel_msg":    "❌ <b>Process cancel kar diya.</b>\nDobara shuru karne ke liye /fetch karo.",
    "py_error_prefix":  "❌ <b>Error:</b>",
}

def _load_cfg() -> dict:
    try:
        if os.path.exists(_CONFIG_FILE):
            with open(_CONFIG_FILE, encoding="utf-8") as f:
                data = json.load(f)
            return {**_DEFAULTS, **{k: v for k, v in data.items() if v}}
    except Exception as e:
        logger.warning(f"bot_config.json read fail: {e}")
    return dict(_DEFAULTS)

def cfg(key: str) -> str:
    return _load_cfg().get(key, _DEFAULTS.get(key, ""))

def _steps(key: str) -> list[str]:
    return [f"<b>{s.strip()}</b>" for s in cfg(key).splitlines() if s.strip()]

BOT_TOKEN = (
    os.environ.get("BOT_TOKEN")
    or cfg("py_bot_token")
    or _DEFAULTS["py_bot_token"]
)

# Conversation states
CAPTCHA_INPUT, OTP_INPUT = range(2)

# Single shared HTTP session
_browser_session: UIDaiSession | None = None

def LOADING_STEPS():  return _steps("py_loading_steps")
def OTP_LOADING_STEPS(): return _steps("py_otp_steps")


async def fake_loading(msg: Message, steps: list[str], delay: float = 0.9) -> None:
    for step in steps:
        try:
            await msg.edit_text(step, parse_mode=ParseMode.HTML)
            await asyncio.sleep(delay)
        except Exception:
            pass


# ── /start ────────────────────────────────────────────────────────────
async def start(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> None:
    await update.message.reply_text(cfg("py_start_msg"), parse_mode=ParseMode.HTML)


# ── /fetch command ────────────────────────────────────────────────────
async def fetch_cmd(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> int:
    args = ctx.args or []
    if len(args) < 2:
        await update.message.reply_text(
            "⚠️ <b>Usage:</b> <code>/fetch &lt;mobile&gt; &lt;fullname&gt;</code>\n"
            "Example: <code>/fetch 9876543210 Ravi Kumar</code>",
            parse_mode=ParseMode.HTML,
        )
        return ConversationHandler.END

    mobile   = args[0].strip()
    fullname = " ".join(args[1:]).strip()

    if not re.fullmatch(r"[6-9]\d{9}", mobile):
        await update.message.reply_text(
            "❌ <b>Invalid mobile number.</b>\n10-digit Indian number dalo (6/7/8/9 se start).",
            parse_mode=ParseMode.HTML,
        )
        return ConversationHandler.END

    session = _browser_session
    if not session:
        await update.message.reply_text("⚠️ Session ready nahi hai. Thodi der mein try karo.")
        return ConversationHandler.END

    steps = LOADING_STEPS()
    status_msg = await update.message.reply_text(steps[0] if steps else "⏳ Loading...", parse_mode=ParseMode.HTML)
    load_task  = asyncio.create_task(fake_loading(status_msg, steps[1:], delay=0.8))

    result = await session.navigate_and_fill(mobile, fullname)
    await load_task

    if not result["ok"]:
        await status_msg.edit_text(
            f"❌ <b>Error:</b> {result['error']}\n\nDobara /fetch karo.",
            parse_mode=ParseMode.HTML,
        )
        return ConversationHandler.END

    ctx.user_data["mobile"]   = mobile
    ctx.user_data["fullname"] = fullname

    await status_msg.edit_text(cfg("py_captcha_msg"), parse_mode=ParseMode.HTML)
    await update.message.reply_photo(
        photo=InputFile(result["captcha_image"], filename="captcha.png"),
        caption="🔡 <b>Captcha text yahan reply karo:</b>",
        parse_mode=ParseMode.HTML,
    )
    return CAPTCHA_INPUT


# ── /refresh captcha ──────────────────────────────────────────────────
async def refresh_captcha(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> int:
    session = _browser_session
    if not session:
        await update.message.reply_text("⚠️ Session nahi mila. /fetch se dobara shuru karo.")
        return ConversationHandler.END

    msg = await update.message.reply_text("🔄 <b>Captcha refresh ho raha hai...</b>", parse_mode=ParseMode.HTML)
    result = await session.refresh_captcha()

    if not result["ok"]:
        await msg.edit_text(f"❌ Refresh fail: {result['error']}", parse_mode=ParseMode.HTML)
        return CAPTCHA_INPUT

    await msg.delete()
    await update.message.reply_photo(
        photo=InputFile(result["captcha_image"], filename="captcha.png"),
        caption="🔡 <b>Naya captcha — text reply karo:</b>",
        parse_mode=ParseMode.HTML,
    )
    return CAPTCHA_INPUT


# ── Captcha received ─────────────────────────────────────────────────
async def captcha_received(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> int:
    captcha_text = update.message.text.strip()
    session = _browser_session

    if not session:
        await update.message.reply_text("⚠️ Session nahi mila. /fetch se dobara shuru karo.")
        return ConversationHandler.END

    status_msg = await update.message.reply_text(
        "⚡ <b>Captcha submit ho raha hai...</b>", parse_mode=ParseMode.HTML
    )

    result = await session.submit_captcha_and_send_otp(captcha_text)

    if not result["ok"]:
        # Show new captcha for retry
        new_cap = result.get("captcha_image")
        await status_msg.edit_text(
            f"❌ <b>Error:</b> {result['error']}\n\n🔄 Naya captcha bheja — dobara try karo.",
            parse_mode=ParseMode.HTML,
        )
        if new_cap:
            await update.message.reply_photo(
                photo=InputFile(new_cap, filename="captcha.png"),
                caption="🔡 <b>Captcha text reply karo:</b>",
                parse_mode=ParseMode.HTML,
            )
        return CAPTCHA_INPUT

    mobile = ctx.user_data.get("mobile", "aapke number")
    await status_msg.edit_text(
        cfg("py_otp_msg").replace("{mobile}", mobile),
        parse_mode=ParseMode.HTML,
    )
    return OTP_INPUT


# ── OTP received ──────────────────────────────────────────────────────
async def otp_received(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> int:
    otp = update.message.text.strip()
    session = _browser_session

    if not session:
        await update.message.reply_text("⚠️ Session nahi mila. /fetch se dobara shuru karo.")
        return ConversationHandler.END

    if not re.fullmatch(r"\d{4,8}", otp):
        await update.message.reply_text(
            "❌ <b>Invalid OTP.</b> Sirf 4-8 digit numbers reply karo.",
            parse_mode=ParseMode.HTML,
        )
        return OTP_INPUT

    osteps = OTP_LOADING_STEPS()
    status_msg = await update.message.reply_text(osteps[0] if osteps else "⏳ Verifying...", parse_mode=ParseMode.HTML)
    load_task  = asyncio.create_task(fake_loading(status_msg, osteps[1:-1], delay=1.0))

    result = await session.submit_otp_and_download(otp)
    await load_task

    if not result["ok"]:
        await status_msg.edit_text(
            f"❌ <b>Error:</b> {result['error']}\n\nOTP expire ho gaya? /fetch se dobara shuru karo.",
            parse_mode=ParseMode.HTML,
        )
        return ConversationHandler.END

    await status_msg.edit_text(osteps[-1] if osteps else "✅ Done.", parse_mode=ParseMode.HTML)
    await asyncio.sleep(0.5)

    file_path = result.get("file_path")
    if file_path and os.path.exists(file_path):
        await update.message.reply_chat_action(ChatAction.UPLOAD_DOCUMENT)
        with open(file_path, "rb") as f:
            await update.message.reply_document(
                document=InputFile(f, filename=os.path.basename(file_path)),
                caption=cfg("py_success_msg"),
                parse_mode=ParseMode.HTML,
            )
        os.remove(file_path)
    else:
        msg = result.get("message", "✅ UID/EID aapke registered mobile pe SMS mein bhej diya gaya.")
        await update.message.reply_text(msg, parse_mode=ParseMode.HTML)

    await status_msg.delete()
    return ConversationHandler.END


# ── /cancel ───────────────────────────────────────────────────────────
async def cancel(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> int:
    ctx.user_data.clear()
    await update.message.reply_text(cfg("py_cancel_msg"), parse_mode=ParseMode.HTML)
    return ConversationHandler.END


# ── Error handler ──────────────────────────────────────────────────────
async def error_handler(update: object, ctx: ContextTypes.DEFAULT_TYPE) -> None:
    logger.error("Exception:", exc_info=ctx.error)
    if isinstance(update, Update) and update.effective_message:
        await update.effective_message.reply_text(
            "⚠️ <b>Unexpected error aaya.</b> /fetch se dobara try karo.",
            parse_mode=ParseMode.HTML,
        )


# ── Startup / Shutdown hooks ───────────────────────────────────────────
async def on_startup(app: Application) -> None:
    global _browser_session
    logger.info("UIDAI HTTP session start ho rahi hai...")
    _browser_session = UIDaiSession()
    await _browser_session.start()
    logger.info("UIDAI session ready.")


async def on_shutdown(app: Application) -> None:
    global _browser_session
    if _browser_session:
        logger.info("Browser session band ho rahi hai...")
        await _browser_session.close()
        _browser_session.cleanup()


# ── Main ───────────────────────────────────────────────────────────────
def main() -> None:
    token = BOT_TOKEN or "8750675658:AAGj9h3DvN8KzSHviWh6g3hiaCkycrk1aoI"

    app = (
        Application.builder()
        .token(token)
        .post_init(on_startup)
        .post_shutdown(on_shutdown)
        .build()
    )

    fetch_cmd_name   = cfg("py_fetch_cmd").lstrip("/")   or "fetch"
    cancel_cmd_name  = cfg("py_cancel_cmd").lstrip("/")  or "cancel"
    refresh_cmd_name = cfg("py_refresh_cmd").lstrip("/") or "refresh"

    conv = ConversationHandler(
        entry_points=[CommandHandler(fetch_cmd_name, fetch_cmd)],
        states={
            CAPTCHA_INPUT: [
                CommandHandler(refresh_cmd_name, refresh_captcha),
                CommandHandler(cancel_cmd_name,  cancel),
                MessageHandler(filters.TEXT & ~filters.COMMAND, captcha_received),
            ],
            OTP_INPUT: [
                CommandHandler(cancel_cmd_name, cancel),
                MessageHandler(filters.TEXT & ~filters.COMMAND, otp_received),
            ],
        },
        fallbacks=[CommandHandler(cancel_cmd_name, cancel)],
        allow_reentry=True,
    )

    app.add_handler(CommandHandler("start", start))
    app.add_handler(conv)
    app.add_error_handler(error_handler)

    logger.info("Bot polling shuru ho raha hai...")
    app.run_polling(drop_pending_updates=True)


if __name__ == "__main__":
    main()
