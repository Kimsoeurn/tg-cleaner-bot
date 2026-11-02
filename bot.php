<?php
/**
 * Telegram Group Cleaner Bot
 * --------------------------
 * Deletes "Name left the group" service messages (and, if you turn it on,
 * "Name joined the group") in groups where this bot is an admin.
 *
 *  - One file, no Composer packages. Needs PHP 8.0+ with the curl extension
 *    (and pdo_sqlite if AUDIT_LOG=true).
 *  - Uses long polling: no domain, SSL certificate or open port needed.
 *  - Never stores or logs the text of anyone's messages. With AUDIT_LOG=true it
 *    records each deletion (who left/joined, where, when) in audit.sqlite.
 *
 * Run:  php bot.php      (settings are read from .env next to this file)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404); // command-line bot only; never run it from a web server
    exit;
}

final class TelegramApi
{
    public function __construct(private string $base, private string $token)
    {
        $this->base = rtrim($base, '/');
    }

    /** Calls a Bot API method and always returns an array with at least 'ok'. */
    public function call(string $method, array $params = [], int $timeout = 15): array
    {
        $ch = curl_init("{$this->base}/bot{$this->token}/{$method}");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params ?: new stdClass()),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $body  = curl_exec($ch);
        $error = curl_error($ch);
        unset($ch);

        if ($body === false) {
            return ['ok' => false, 'network' => true, 'description' => "network error: {$error}"];
        }
        $data = json_decode((string) $body, true);

        return is_array($data) ? $data : ['ok' => false, 'description' => 'invalid response from Telegram'];
    }
}

/** Records every service message the bot deletes (or fails to delete) in an SQLite file. */
final class AuditLog
{
    private PDO $db;
    private int $prunedAt = 0;

    /** @param int $retentionDays Records older than this are removed. 0 = keep forever. */
    public function __construct(string $file, private int $retentionDays)
    {
        $this->db = new PDO("sqlite:{$file}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5, // wait instead of failing while someone reads the file
        ]);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS deletions (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            logged_at  TEXT    NOT NULL, -- when the bot acted, e.g. 2026-11-02 10:19:24+07:00
            message_at TEXT,             -- when Telegram posted the service message
            chat_id    INTEGER NOT NULL,
            chat_title TEXT,
            message_id INTEGER NOT NULL,
            kind       TEXT    NOT NULL, -- left | joined
            user_id    INTEGER,          -- the person who left or joined
            username   TEXT,
            user_name  TEXT,
            deleted    INTEGER NOT NULL, -- 1 = deleted, 0 = failed (see error)
            error      TEXT
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS deletions_chat ON deletions (chat_id, id)');
        @chmod($file, 0600); // it holds user IDs and names, so only the bot's user may read it

        $this->pruneIfDue();
    }

    /** Writes one row per person the service message was about. Never throws. */
    public function record(array $m, string $kind, bool $deleted, ?string $error): void
    {
        $users = isset($m['left_chat_member']) ? [$m['left_chat_member']] : ($m['new_chat_members'] ?? []);

        try {
            $insert = $this->db->prepare('INSERT INTO deletions
                (logged_at, message_at, chat_id, chat_title, message_id, kind, user_id, username, user_name, deleted, error)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($users ?: [null] as $user) {
                $insert->execute([
                    date('Y-m-d H:i:sP'),
                    isset($m['date']) ? date('Y-m-d H:i:sP', (int) $m['date']) : null,
                    (int) $m['chat']['id'],
                    $m['chat']['title'] ?? null,
                    (int) $m['message_id'],
                    $kind,
                    isset($user['id']) ? (int) $user['id'] : null,
                    $user['username'] ?? null,
                    trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: null,
                    $deleted ? 1 : 0,
                    $error,
                ]);
            }
        } catch (PDOException $e) {
            log_line('Could not write to the audit log: ' . $e->getMessage());
        }
    }

    /** Removes expired records, at most once an hour. Never throws. */
    public function pruneIfDue(): void
    {
        if ($this->retentionDays <= 0 || time() - $this->prunedAt < 3600) {
            return;
        }
        $this->prunedAt = time();

        try {
            $delete = $this->db->prepare("DELETE FROM deletions WHERE CAST(strftime('%s', logged_at) AS INTEGER) < ?");
            $delete->execute([time() - $this->retentionDays * 86400]);
            if ($delete->rowCount() > 0) {
                log_line("Removed {$delete->rowCount()} audit record(s) older than {$this->retentionDays} days.");
            }
        } catch (PDOException $e) {
            log_line('Could not clean up the audit log: ' . $e->getMessage());
        }
    }
}

final class CleanerBot
{
    private int $botId = 0;
    private string $botUsername = '';
    /** @var array<int, true> Chats not in ALLOWED_CHAT_IDS that were already logged once. */
    private array $ignoredChatIds = [];

    /** @param int[] $allowedChatIds Empty = work in any group. */
    public function __construct(
        private TelegramApi $api,
        private bool $deleteJoin,
        private array $allowedChatIds,
        private ?AuditLog $audit = null,
    ) {
    }

    public function run(): void
    {
        $this->connect();

        log_line(sprintf(
            'Running as @%s | deleting: %s | groups: %s | audit log: %s',
            $this->botUsername,
            $this->deleteJoin ? 'left + joined' : 'left only',
            $this->allowedChatIds ? implode(', ', $this->allowedChatIds) : 'any group where I am admin',
            $this->audit ? 'on' : 'off'
        ));

        $offset = 0;
        while (true) {
            $this->audit?->pruneIfDue();

            $res = $this->api->call('getUpdates', [
                'offset'          => $offset,
                'timeout'         => 50,          // long poll: Telegram holds the request until something happens
                'allowed_updates' => ['message'],
            ], 65);

            if (!($res['ok'] ?? false)) {
                $this->handlePollError($res);
                continue;
            }

            foreach ($res['result'] as $update) {
                $offset = $update['update_id'] + 1;
                if (isset($update['message'])) {
                    $this->handleMessage($update['message']);
                }
            }
        }
    }

    private function connect(): void
    {
        while (true) {
            $me = $this->api->call('getMe');
            if ($me['ok'] ?? false) {
                $this->botId       = (int) $me['result']['id'];
                $this->botUsername = (string) $me['result']['username'];
                return;
            }
            if (($me['error_code'] ?? 0) === 401) {
                log_line('Telegram rejected BOT_TOKEN. Copy it again from @BotFather into .env.');
                exit(1);
            }
            log_line('Cannot reach Telegram (' . ($me['description'] ?? 'unknown error') . '). Retrying in 10s...');
            sleep(10);
        }
    }

    private function handlePollError(array $res): void
    {
        $code = (int) ($res['error_code'] ?? 0);
        $desc = $res['description'] ?? 'unknown error';

        if ($code === 401) {
            log_line('Telegram rejected BOT_TOKEN. Copy it again from @BotFather into .env.');
            exit(1);
        }
        if ($code === 409) {
            log_line("Conflict ({$desc}). Another copy of this bot is running, or this token has a webhook "
                . '(for example from n8n). Use a separate bot just for this. Retrying in 30s...');
            sleep(30);
            return;
        }
        if ($code === 429) {
            sleep(max(1, (int) ($res['parameters']['retry_after'] ?? 5)));
            return;
        }
        log_line("getUpdates failed ({$desc}). Retrying in 5s...");
        sleep(5);
    }

    private function handleMessage(array $m): void
    {
        $chatType = $m['chat']['type'] ?? '';
        if ($chatType !== 'group' && $chatType !== 'supergroup') {
            return; // ignore private chats and channels
        }

        $chatId = (int) $m['chat']['id'];
        $label  = sprintf('"%s" (%d)', $m['chat']['title'] ?? '?', $chatId);

        $this->followMigration($m, $chatId);

        if (!$this->isAllowed($chatId)) {
            // Only leave when someone adds the bot. A group it is already in (for example one
            // that became a supergroup while the bot was restarting) is ignored, never left.
            if ($this->wasJustAdded($m)) {
                log_line("{$label} is not in ALLOWED_CHAT_IDS, leaving it.");
                $this->api->call('leaveChat', ['chat_id' => $chatId]);
            } elseif (!isset($this->ignoredChatIds[$chatId])) {
                $this->ignoredChatIds[$chatId] = true;
                log_line("Ignoring {$label}: not in ALLOWED_CHAT_IDS. If it is your group, add {$chatId} to .env and restart.");
            }
            return;
        }

        if ($this->isChatIdCommand($m['text'] ?? '')) {
            $this->api->call('sendMessage', ['chat_id' => $chatId, 'text' => "Chat ID: {$chatId}"]);
            return;
        }

        if (isset($m['left_chat_member'])) {
            if ((int) $m['left_chat_member']['id'] === $this->botId) {
                log_line("I was removed from {$label}.");
                return;
            }
            $this->deleteServiceMessage($m, $label, 'left');
            return;
        }

        if (isset($m['new_chat_members'])) {
            foreach ($m['new_chat_members'] as $user) {
                if ((int) $user['id'] === $this->botId) {
                    log_line("Added to {$label}. Make me an admin with the 'Delete messages' permission.");
                }
            }
            if ($this->deleteJoin) {
                $this->deleteServiceMessage($m, $label, 'joined');
            }
        }
    }

    private function deleteServiceMessage(array $m, string $label, string $kind): void
    {
        $params = ['chat_id' => (int) $m['chat']['id'], 'message_id' => (int) $m['message_id']];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $res = $this->api->call('deleteMessage', $params);
            if ($res['ok'] ?? false) {
                log_line("Deleted a '{$kind}' message in {$label}.");
                $this->audit?->record($m, $kind, true, null);
                return;
            }
            if ($attempt === 1 && ($res['error_code'] ?? 0) === 429) {
                sleep(max(1, (int) ($res['parameters']['retry_after'] ?? 1)));
                continue;
            }
            if ($attempt === 1 && ($res['network'] ?? false)) {
                sleep(2);
                continue;
            }
            break;
        }

        $desc = $res['description'] ?? 'unknown error';
        $hint = preg_match("/can't be deleted|not enough rights|admin/i", $desc)
            ? " -> Make the bot an admin with the 'Delete messages' permission."
            : '';
        log_line("Could not delete a '{$kind}' message in {$label}: {$desc}{$hint}");
        $this->audit?->record($m, $kind, false, $desc);
    }

    /** When a basic group is upgraded to a supergroup its ID changes; keep it allowed. */
    private function followMigration(array $m, int $chatId): void
    {
        if (!$this->allowedChatIds) {
            return;
        }
        $pairs = [];
        if (isset($m['migrate_to_chat_id'])) {
            $pairs[] = [$chatId, (int) $m['migrate_to_chat_id']];
        }
        if (isset($m['migrate_from_chat_id'])) {
            $pairs[] = [(int) $m['migrate_from_chat_id'], $chatId];
        }
        foreach ($pairs as [$old, $new]) {
            if (in_array($old, $this->allowedChatIds, true) && !in_array($new, $this->allowedChatIds, true)) {
                $this->allowedChatIds[] = $new;
                log_line("Group {$old} became supergroup {$new}. Allowed for now; add {$new} to ALLOWED_CHAT_IDS in .env.");
            }
        }
    }

    private function isAllowed(int $chatId): bool
    {
        return !$this->allowedChatIds || in_array($chatId, $this->allowedChatIds, true);
    }

    /** True if this message is the bot being added to the chat (or the chat being created with it). */
    private function wasJustAdded(array $m): bool
    {
        if (!empty($m['group_chat_created'])) {
            return true;
        }
        foreach ($m['new_chat_members'] ?? [] as $user) {
            if ((int) $user['id'] === $this->botId) {
                return true;
            }
        }

        return false;
    }

    private function isChatIdCommand(string $text): bool
    {
        if (!preg_match('/^\/chatid(?:@(\w+))?$/i', trim($text), $match)) {
            return false;
        }

        return !isset($match[1]) || strcasecmp($match[1], $this->botUsername) === 0;
    }
}

function log_line(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] {$message}" . PHP_EOL);
}

/** Reads KEY=value lines from .env; real environment variables win. */
function load_config(string $file): array
{
    $config = [];
    if (is_file($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            if (preg_match('/^(["\'])(.*?)\1/', $value, $quoted)) {
                $value = $quoted[2];                                   // KEY="value"  # comment
            } else {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $value), "\"'"); // KEY=value  # comment
            }
            $config[trim($key)] = $value;
        }
    }
    foreach (['BOT_TOKEN', 'DELETE_JOIN', 'ALLOWED_CHAT_IDS', 'TIMEZONE', 'TELEGRAM_API_BASE', 'AUDIT_LOG', 'AUDIT_RETENTION_DAYS'] as $key) {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            $config[$key] = $env;
        }
    }

    return $config;
}

/** "-100123, -100456" -> [-100123, -100456] */
function parse_ids(string $list): array
{
    $ids = [];
    foreach (explode(',', $list) as $part) {
        $part = trim($part);
        if (preg_match('/^-?\d+$/', $part)) {
            $ids[] = (int) $part;
        }
    }

    return $ids;
}

// ---- Start ---------------------------------------------------------------

$config = load_config(__DIR__ . '/.env');
date_default_timezone_set($config['TIMEZONE'] ?? 'Asia/Phnom_Penh');

if (($config['BOT_TOKEN'] ?? '') === '') {
    log_line('BOT_TOKEN is missing. Copy .env.example to .env and put your token in it.');
    exit(1);
}

$audit = null;
if (filter_var($config['AUDIT_LOG'] ?? 'false', FILTER_VALIDATE_BOOLEAN)) {
    if (!extension_loaded('pdo_sqlite')) {
        log_line(sprintf(
            'AUDIT_LOG=true needs the PHP SQLite extension. Install it (sudo apt install php%d.%d-sqlite3) or set AUDIT_LOG=false.',
            PHP_MAJOR_VERSION,
            PHP_MINOR_VERSION
        ));
        exit(1);
    }
    $auditFile = __DIR__ . '/audit.sqlite';
    try {
        $audit = new AuditLog($auditFile, max(0, (int) ($config['AUDIT_RETENTION_DAYS'] ?? 0)));
    } catch (PDOException $e) {
        log_line("Cannot open the audit log {$auditFile}: {$e->getMessage()}");
        exit(1);
    }
}

(new CleanerBot(
    new TelegramApi($config['TELEGRAM_API_BASE'] ?? 'https://api.telegram.org', $config['BOT_TOKEN']),
    deleteJoin: filter_var($config['DELETE_JOIN'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
    allowedChatIds: parse_ids($config['ALLOWED_CHAT_IDS'] ?? ''),
    audit: $audit,
))->run();
