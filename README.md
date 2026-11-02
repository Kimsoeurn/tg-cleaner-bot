# Telegram Group Cleaner Bot

Deletes **"Name left the group"** messages automatically. It can also delete **"Name joined the group"** messages if you turn that on.

- One PHP file, no Composer packages (PHP 8.0+ with `curl`, which every Forge server already has)
- Uses long polling, so you don't need a domain, SSL certificate or webhook
- Runs on your own server, and it never saves or logs what people write

---

## 1. Create the bot

1. In Telegram, open **@BotFather** and send `/newbot`.
2. Give it a name (for example `Niyum Group Cleaner`) and a username ending in `bot` (for example `niyum_cleaner_bot`).
3. Copy the **token** BotFather gives you.

> Create a **new** bot just for this. Don't reuse the bot you use with n8n. That one uses a webhook, and a token with a webhook can't also use long polling.

## 2. Put it on your server

Upload the folder to your server, for example `/home/forge/tg-cleaner-bot`, then run:

```bash
cd /home/forge/tg-cleaner-bot
cp .env.example .env
nano .env            # paste your token into BOT_TOKEN=
php bot.php          # test run
```

You should see:

```
[2026-11-02 10:19:24] Running as @niyum_cleaner_bot | deleting: left only | groups: any group where I am admin
```

Press `Ctrl+C` to stop the test run.

## 3. Add the bot to your group as admin

1. Open the group, then **Group info → Add members** and add your bot.
2. **Edit → Administrators → Add Admin**, and pick your bot.
3. Turn **OFF** every permission **except "Delete messages"**, then save.

That's it. When someone leaves, the bot deletes the "left the group" message within about a second.

## 4. Keep it running 24/7 (Laravel Forge)

In Forge, open **your server → Daemons** (newer Forge versions may call this *Background processes*) and create a new one:

| Field | Value |
|---|---|
| Command | `php bot.php` |
| Directory | `/home/forge/tg-cleaner-bot` |
| User | `forge` |
| Processes | `1` |

Forge uses Supervisor, so the bot restarts by itself if it crashes or the server reboots. You can read its log from the same page.

**After you change `.env`, restart the daemon** so it picks up the new settings.

<details>
<summary>Not using Forge? systemd example</summary>

`/etc/systemd/system/tg-cleaner-bot.service`:

```ini
[Unit]
Description=Telegram Group Cleaner Bot
After=network-online.target

[Service]
WorkingDirectory=/home/forge/tg-cleaner-bot
ExecStart=/usr/bin/php bot.php
Restart=always
User=forge

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now tg-cleaner-bot
journalctl -u tg-cleaner-bot -f     # view log
```
</details>

---

## Settings (`.env`)

| Setting | What it does |
|---|---|
| `BOT_TOKEN` | Your token from @BotFather (required) |
| `DELETE_JOIN` | `true` to also delete "joined the group" messages. Default `false` |
| `ALLOWED_CHAT_IDS` | Optional. Only work in these groups, separated by commas. If set, the bot **leaves any other group** someone adds it to |
| `TIMEZONE` | Timezone for log times. Default `Asia/Phnom_Penh` |

### Lock the bot to your groups (recommended)

Without this, anyone who finds your bot's username could add it to their own group. It wouldn't do any harm, but it's cleaner to lock it.

1. In your group, send `/chatid` and the bot replies with the ID (for example `Chat ID: -1001234567890`).
2. Put the ID in `.env`, for example `ALLOWED_CHAT_IDS=-1001234567890,-1009876543210`.
3. Restart the daemon.

If a normal group gets upgraded to a supergroup, its ID changes. The bot notices this and keeps working, and the log tells you the new ID to put in `.env`.

---

## Good to know

- **Groups with more than 50 members:** Telegram itself no longer posts "left the group" there, so there's nothing to delete. In those groups the bot is mainly useful with `DELETE_JOIN=true`.
- **It only cleans new messages,** meaning ones that arrive while the bot is running. Delete old ones by hand.
- **Run only one copy** per token. If two run at once, the log shows `Conflict`.
- **Privacy:** Telegram sends every group message to admin bots. This script ignores everything except join/leave messages and `/chatid`, and it never stores or logs message text. Everything stays on your server.

## Log messages

| Log line | Meaning |
|---|---|
| `Deleted a 'left' message in ...` | Working ✅ |
| `Could not delete ... Make the bot an admin with the 'Delete messages' permission` | Do step 3 again |
| `Telegram rejected BOT_TOKEN` | Wrong token in `.env` |
| `Conflict ...` | Two copies running, or the token has a webhook (from n8n). Use a separate bot |
| `... is not in ALLOWED_CHAT_IDS, leaving it` | Someone added the bot to another group, so it left |
