<?php
/**
 * Telegram Group Cleaner Bot
 * --------------------------
 * Deletes "Name left the group" service messages (and, if you turn it on,
 * "Name joined the group") in groups where this bot is an admin.
 *
 *  - One file, no Composer packages. Needs PHP 8.0+ with the curl extension.
 *  - Uses long polling: no domain, SSL certificate or open port needed.
 *  - Never stores or logs the text of anyone's messages.
 *
 * Run:  php bot.php      (settings are read from .env next to this file)
 */

declare(strict_types=1);

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

final class CleanerBot
{
    private int $botId = 0;
    private string $botUsername = '';

    /** @param int[] $allowedChatIds Empty = work in any group. */
    public function __construct(
        private TelegramApi $api,
        private bool $deleteJoin,
        private array $allowedChatIds,
    ) {
    }

    public function run(): void
    {
        $this->connect();

        log_line(sprintf(
            'Running as @%s | deleting: %s | groups: %s',
            $this->botUsername,
            $this->deleteJoin ? 'left + joined' : 'left only',
            $this->allowedChatIds ? implode(', ', $this->allowedChatIds) : 'any group where I am admin'
        ));

        $offset = 0;
        while (true) {
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
            log_line("{$label} is not in ALLOWED_CHAT_IDS, leaving it.");
            $this->api->call('leaveChat', ['chat_id' => $chatId]);
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
            $config[trim($key)] = trim(trim($value), "\"'");
        }
    }
    foreach (['BOT_TOKEN', 'DELETE_JOIN', 'ALLOWED_CHAT_IDS', 'TIMEZONE', 'TELEGRAM_API_BASE'] as $key) {
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

(new CleanerBot(
    new TelegramApi($config['TELEGRAM_API_BASE'] ?? 'https://api.telegram.org', $config['BOT_TOKEN']),
    deleteJoin: filter_var($config['DELETE_JOIN'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
    allowedChatIds: parse_ids($config['ALLOWED_CHAT_IDS'] ?? ''),
))->run();
