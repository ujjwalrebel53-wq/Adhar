"""
Aadhaar Retrieve Bot — Telegram bot using python-telegram-bot + Playwright.

Flow:
  /fetch <mobile> <fullname>
     → fills UIDAI form → sends captcha image
  User sends captcha text
     → submits captcha → triggers OTP → confirms OTP sent
  User sends OTP
     → submits OTP → downloads PDF → sends PDF to user

Set BOT_TOKEN env var before running:
  export BOT_TOKEN="your:token"
  python bot.py
"""

import asyncio
import logging
import os
import re
import tempfile
from typing import Optional

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

# ── Conversation states ──────────────────────────────────────────────
CAPTCHA_INPUT, OTP_INPUT = range(2)

# ── Hacker-theme loading messages ────────────────────────────────────
LOADING_STEPS = [
    "🔐 <b>Initializing secure tunnel...</b>",
    "🛰️ <b>Connecting to UIDAI node...</b>",
    "🧬 <b>Injecting session payload...</b>",
    "🔍 <b>Resolving biometric endpoint...</b>",
    "⚡ <b>Bypassing sandbox layer...</b>",
    "🗝️ <b>Decrypting identity matrix...</b>",
    "📡 <b>Establishing encrypted channel...</b>",
    "✅ <b>Access granted. Fetching data...</b>",
]

OTP_LOADING_STEPS = [
    "🔐 <b>Validating OTP token...</b>",
    "🧬 <b>Cross-referencing biometric hash...</b>",
    "📂 <b>Locating encrypted Aadhaar file...</b>",
    "⬇️ <b>Decrypting and packaging document...</b>",
    "✅ <b>Document secured. Sending...</b>",
]


async def fake_loading(msg: Message, steps: list[str], delay: float = 0.9) -> None:
    """Animate loading steps by editing a single message."""
    for step in steps:
        try:
            await msg.edit_text(step, parse_mode=ParseMode.HTML)
            await asyncio.sleep(delay)
        except Exception:
            pass


# ── /fetch command ────────────────────────────────────────────────────
async def fetch_cmd(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> int:
    args = ctx.args or []
    if len(args) < 2:
        await update.message.reply_text(
            "⚠️ Usage: <code>/fetch &lt;mobile&gt; &lt;fullname&gt;</code>\n"
            "Example: <code>/fetch 9876543210 Ravi Kumar</code>",
            parse_mode=ParseMode.HTML,
        )
        return ConversationHandler.END

    mobile   = args[0].strip()
    fullname = " ".join(args[1:]).strip()

    if not re.fullmatch(r"[6-9]\d{9}", mobile):
        await update.message.reply_text("❌ <b>Invalid mobile number.</b> 10-digit Indian number dalo.", parse_mode=ParseMode.HTML)
        return ConversationHandler.END

    # Send initial status message
    status_msg = await update.message.reply_text(LOADING_STEPS[0], parse_mode=ParseMode.HTML)

    # Fake loading animation while browser starts
    load_task = asyncio.create_task(fake_loading(status_msg, LOADING_STEPS[1:], delay=0.85))

    # Start Playwright session
    session = UIDaiSession()
    await session.start(headless=True)

    result = await session.navigate_and_fill(mobile, fullname)

    await load_task  # let animation finish

    if not result["ok"]:
        await session.close()
        await status_msg.edit_text(
            f"❌ <b>Error:</b> {result['error']}\n\nDobara /fetch karo.",
            parse_mode=ParseMode.HTML,
        )
        return ConversationHandler.END

    # Store session in user_data
    ctx.user_data["session"] = session
    ctx.user_data["mobile"]   = mobile
    ctx.user_data["fullname"] = fullname

    # Send captcha image
    captcha_bytes = result["captcha_image"]
    await status_msg.edit_text(
        "📸 <b>Captcha ready.</b> Neeche image dekho aur text reply karo.\n"
        "<i>Refresh ke liye /refresh likho | Cancel ke liye /cancel</i>",
        parse_mode=ParseMode.HTML,
    )
    await update.message.reply_photo(
        photo=InputFile(captcha_bytes, filename="captcha.png"),
        caption="🔡 <b>Captcha text reply karo:</b>",
        parse_mode=ParseMode.HTML,
    )
    return CAPTCHA_INPUT


# ── /refresh captcha ─────────────────────────────────────────────────
async def refresh_captcha(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> int:
    session: Optional[UIDaiSession] = ctx.user_data.get("session")
    if not session:
        await update.message.reply_text("⚠️ Session expired. /fetch se dobara shuru karo.")
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


# ── Captcha received → submit & send OTP ─────────────────────────────
async def captcha_received(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> int:
    captcha_text = update.message.text.strip()
    session: Optional[UIDaiSession] = ctx.user_data.get("session")

    if not session:
        await update.message.reply_text("⚠️ Session expired. /fetch se dobara shuru karo.")
        return ConversationHandler.END

    status_msg = await update.message.reply_text(
        "⚡ <b>Captcha submit ho raha hai...</b>", parse_mode=ParseMode.HTML
    )

    result = await session.submit_captcha_and_send_otp(captcha_text)

    if not result["ok"]:
        err = result["error"]
        # Maybe captcha was wrong — offer retry
        new_captcha = await session.refresh_captcha()
        if new_captcha["ok"]:
            await status_msg.edit_text(
                f"❌ <b>Error:</b> {err}\n\n🔄 Naya captcha bheja — dobara try karo.",
                parse_mode=ParseMode.HTML,
            )
            await update.message.reply_photo(
                photo=InputFile(new_captcha["captcha_image"], filename="captcha.png"),
                caption="🔡 <b>Captcha text reply karo:</b>",
                parse_mode=ParseMode.HTML,
            )
            return CAPTCHA_INPUT
        else:
            await status_msg.edit_text(f"❌ <b>Error:</b> {err}", parse_mode=ParseMode.HTML)
            await session.close()
            return ConversationHandler.END

    mobile = ctx.user_data.get("mobile", "your number")
    await status_msg.edit_text(
        f"📲 <b>OTP bheja gaya!</b>\n"
        f"📱 Mobile <code>{mobile}</code> pe OTP aaya hoga.\n\n"
        f"🔢 OTP reply karo:\n<i>Cancel ke liye /cancel</i>",
        parse_mode=ParseMode.HTML,
    )
    return OTP_INPUT


# ── OTP received → submit & download ─────────────────────────────────
async def otp_received(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> int:
    otp = update.message.text.strip()
    session: Optional[UIDaiSession] = ctx.user_data.get("session")

    if not session:
        await update.message.reply_text("⚠️ Session expired. /fetch se dobara shuru karo.")
        return ConversationHandler.END

    if not re.fullmatch(r"\d{4,8}", otp):
        await update.message.reply_text("❌ Invalid OTP format. Sirf numbers dalo (4-8 digits).")
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
        await session.close()
        session.cleanup()
        ctx.user_data.clear()
        return ConversationHandler.END

    file_path = result["file_path"]
    await status_msg.edit_text(OTP_LOADING_STEPS[-1], parse_mode=ParseMode.HTML)
    await asyncio.sleep(0.5)

    # Send the PDF
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

    await status_msg.delete()

    # Cleanup
    await session.close()
    session.cleanup()
    ctx.user_data.clear()
    return ConversationHandler.END


# ── /cancel ──────────────────────────────────────────────────────────
async def cancel(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> int:
    session: Optional[UIDaiSession] = ctx.user_data.get("session")
    if session:
        await session.close()
        session.cleanup()
    ctx.user_data.clear()
    await update.message.reply_text(
        "❌ <b>Process cancel kar diya.</b>\nDobara shuru karne ke liye /fetch karo.",
        parse_mode=ParseMode.HTML,
    )
    return ConversationHandler.END


# ── /start ────────────────────────────────────────────────────────────
async def start(update: Update, ctx: ContextTypes.DEFAULT_TYPE) -> None:
    await update.message.reply_text(
        "👾 <b>Aadhaar Retrieve Bot</b> — Active\n\n"
        "📌 <b>Command:</b>\n"
        "<code>/fetch &lt;mobile&gt; &lt;fullname&gt;</code>\n\n"
        "Example:\n"
        "<code>/fetch 9876543210 Ravi Kumar</code>\n\n"
        "Bot automatically:\n"
        "1️⃣ UIDAI site pe form fill karega\n"
        "2️⃣ Captcha dikhayega\n"
        "3️⃣ OTP maangega\n"
        "4️⃣ Aadhaar PDF download karke bhejega\n\n"
        "⚠️ <i>Sirf apna khud ka Aadhaar retrieve karo.</i>",
        parse_mode=ParseMode.HTML,
    )


# ── Error handler ─────────────────────────────────────────────────────
async def error_handler(update: object, ctx: ContextTypes.DEFAULT_TYPE) -> None:
    logger.error("Exception:", exc_info=ctx.error)
    if isinstance(update, Update) and update.effective_message:
        await update.effective_message.reply_text(
            "⚠️ <b>Unexpected error aaya.</b> /fetch se dobara try karo.",
            parse_mode=ParseMode.HTML,
        )


# ── Main ──────────────────────────────────────────────────────────────
def main() -> None:
    if not BOT_TOKEN:
        raise RuntimeError("BOT_TOKEN environment variable set nahi hai!")

    app = Application.builder().token(BOT_TOKEN).build()

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

    logger.info("Bot starting...")
    app.run_polling(drop_pending_updates=True)


if __name__ == "__main__":
    main()
