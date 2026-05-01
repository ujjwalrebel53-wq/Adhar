"""
Aadhaar Retrieve Bot — Persistent Playwright browser + Telegram.

Browser ek baar start hota hai aur 24/7 UIDAI page pe ready rehta hai.
/fetch <mobile> <name>  → form fill → captcha image → OTP → PDF/SMS

Set env vars:
  export BOT_TOKEN="your:token"
  python bot.py
"""

import asyncio
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

from uidai_browser import UIDaiSession

logging.basicConfig(
    format="%(asctime)s | %(levelname)s | %(name)s | %(message)s",
    level=logging.INFO,
)
logger = logging.getLogger(__name__)

BOT_TOKEN = os.environ.get("BOT_TOKEN", "")

# Conversation states
CAPTCHA_INPUT, OTP_INPUT = range(2)

# Single shared browser session (24/7 open)
_browser_session: UIDaiSession | None = None

# ── Hacker loading messages ─────────────────────────────────────────
LOADING_STEPS = [
    "🔐 <b>Secure tunnel initialize ho raha hai...</b>",
    "🛰️ <b>UIDAI node se connect ho raha hai...</b>",
    "🧬 <b>Session payload inject ho raha hai...</b>",
    "🔍 <b>Biometric endpoint resolve ho raha hai...</b>",
    "⚡ <b>Sandbox bypass ho raha hai...</b>",
    "🗝️ <b>Identity matrix decrypt ho rahi hai...</b>",
    "📋 <b>Form fill ho raha hai...</b>",
    "📸 <b>Captcha capture ho raha hai...</b>",
]

OTP_LOADING_STEPS = [
    "🔐 <b>OTP token validate ho raha hai...</b>",
    "🧬 <b>Biometric hash cross-reference ho raha hai...</b>",
    "📂 <b>Encrypted Aadhaar file locate ho rahi hai...</b>",
    "⬇️ <b>Document decrypt aur package ho raha hai...</b>",
    "✅ <b>Document secured. Bhej raha hoon...</b>",
]


async def fake_loading(msg: Message, steps: list[str], delay: float = 0.9) -> None:
    for step in steps:
        try:
            await msg.edit_text(step, parse_mode=ParseMode.HTML)
            await asyncio.sleep(delay)
        except Exception:
            pass


# ── /start ────────────────────────────────────────────────────────────
async def start(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> None:
    await update.message.reply_text(
        "👾 <b>Aadhaar Retrieve Bot</b> — Online ✅\n\n"
        "📌 <b>Command:</b>\n"
        "<code>/fetch &lt;mobile&gt; &lt;fullname&gt;</code>\n\n"
        "📍 Example:\n"
        "<code>/fetch 9876543210 Ravi Kumar</code>\n\n"
        "Bot kya karega:\n"
        "1️⃣ UIDAI form me naam aur mobile fill karega\n"
        "2️⃣ Captcha image tumhe dikhayega\n"
        "3️⃣ Captcha text reply karo\n"
        "4️⃣ OTP aayega mobile pe\n"
        "5️⃣ OTP reply karo → Aadhaar PDF aayega\n\n"
        "⚠️ <i>Sirf apna khud ka Aadhaar retrieve karo.</i>",
        parse_mode=ParseMode.HTML,
    )


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
    if not session or not session.page:
        await update.message.reply_text("⚠️ Browser session ready nahi hai. Thodi der mein try karo.")
        return ConversationHandler.END

    status_msg = await update.message.reply_text(LOADING_STEPS[0], parse_mode=ParseMode.HTML)
    load_task  = asyncio.create_task(fake_loading(status_msg, LOADING_STEPS[1:], delay=0.8))

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

    await status_msg.edit_text(
        "📸 <b>Captcha ready hai!</b>\n\n"
        "Neeche captcha image dekho aur <b>text reply karo.</b>\n"
        "<i>/refresh = naya captcha | /cancel = band karo</i>",
        parse_mode=ParseMode.HTML,
    )
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
        f"📲 <b>OTP bheja gaya!</b>\n"
        f"📱 <code>{mobile}</code> pe OTP aaya hoga.\n\n"
        f"🔢 <b>OTP reply karo:</b>\n"
        f"<i>/cancel = band karo</i>",
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

    status_msg = await update.message.reply_text(OTP_LOADING_STEPS[0], parse_mode=ParseMode.HTML)
    load_task  = asyncio.create_task(fake_loading(status_msg, OTP_LOADING_STEPS[1:-1], delay=1.0))

    result = await session.submit_otp_and_download(otp)
    await load_task

    if not result["ok"]:
        await status_msg.edit_text(
            f"❌ <b>Error:</b> {result['error']}\n\nOTP expire ho gaya? /fetch se dobara shuru karo.",
            parse_mode=ParseMode.HTML,
        )
        return ConversationHandler.END

    await status_msg.edit_text(OTP_LOADING_STEPS[-1], parse_mode=ParseMode.HTML)
    await asyncio.sleep(0.5)

    file_path = result.get("file_path")
    if file_path and os.path.exists(file_path):
        await update.message.reply_chat_action(ChatAction.UPLOAD_DOCUMENT)
        with open(file_path, "rb") as f:
            await update.message.reply_document(
                document=InputFile(f, filename=os.path.basename(file_path)),
                caption=(
                    "✅ <b>Aadhaar document ready!</b>\n"
                    "🔒 <i>Yeh file sirf aapke liye hai. Safely store karo.</i>"
                ),
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
    await update.message.reply_text(
        "❌ <b>Process cancel kar diya.</b>\nDobara shuru karne ke liye /fetch karo.",
        parse_mode=ParseMode.HTML,
    )
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
    logger.info("Browser session start ho raha hai...")
    _browser_session = UIDaiSession()
    await _browser_session.start(headless=True)
    logger.info("Browser session ready — UIDAI page loaded.")


async def on_shutdown(app: Application) -> None:
    global _browser_session
    if _browser_session:
        logger.info("Browser session band ho rahi hai...")
        await _browser_session.close()
        _browser_session.cleanup()


# ── Main ───────────────────────────────────────────────────────────────
def main() -> None:
    if not BOT_TOKEN:
        raise RuntimeError("BOT_TOKEN environment variable set nahi hai!")

    app = (
        Application.builder()
        .token(BOT_TOKEN)
        .post_init(on_startup)
        .post_shutdown(on_shutdown)
        .build()
    )

    conv = ConversationHandler(
        entry_points=[CommandHandler("fetch", fetch_cmd)],
        states={
            CAPTCHA_INPUT: [
                CommandHandler("refresh", refresh_captcha),
                CommandHandler("cancel",  cancel),
                MessageHandler(filters.TEXT & ~filters.COMMAND, captcha_received),
            ],
            OTP_INPUT: [
                CommandHandler("cancel", cancel),
                MessageHandler(filters.TEXT & ~filters.COMMAND, otp_received),
            ],
        },
        fallbacks=[CommandHandler("cancel", cancel)],
        allow_reentry=True,
    )

    app.add_handler(CommandHandler("start", start))
    app.add_handler(conv)
    app.add_error_handler(error_handler)

    logger.info("Bot polling shuru ho raha hai...")
    app.run_polling(drop_pending_updates=True)


if __name__ == "__main__":
    main()
