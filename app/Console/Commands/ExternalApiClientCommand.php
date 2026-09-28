<?php

namespace App\Console\Commands;

use App\Models\ExternalApiClient;
use Illuminate\Console\Command;

/**
 * إدارة تطبيقات الـAPI الخارجي (مفاتيح X-API-Key وwebhooks):
 *
 *   php artisan external-api:client create "Driver App" --webhook-url=https://app.example.com/hooks/o2 --origin=https://app.example.com --rate=120
 *   php artisan external-api:client list
 *   php artisan external-api:client revoke 3        (أو بادئة المفتاح: o2tw_AbCdEfG)
 *
 * المفتاح وسرّ الـwebhook بيطلعوا مرة وحدة بس عند الإنشاء — بنخزّن hash المفتاح بس، فما في طريقة نرجّعه.
 */
class ExternalApiClientCommand extends Command
{
    protected $signature = 'external-api:client
        {action : create | list | revoke}
        {target? : Client name (create) or id / key prefix (revoke)}
        {--webhook-url= : URL that receives signed webhooks (optional)}
        {--origin=* : Allowed browser origin, repeatable — only if the app calls from a browser}
        {--rate=60 : Max requests per minute for this key}';

    protected $description = 'Create, list or revoke external API clients (takeaway integration keys)';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'create' => $this->create(),
            'list' => $this->listClients(),
            'revoke' => $this->revoke(),
            default => $this->failWith('Unknown action. Use: create | list | revoke'),
        };
    }

    private function create(): int
    {
        $name = trim((string) $this->argument('target'));
        if ($name === '') {
            return $this->failWith('Client name is required: external-api:client create "Driver App"');
        }

        $webhookUrl = $this->option('webhook-url') ?: null;
        if ($webhookUrl && ! filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            return $this->failWith('Invalid --webhook-url.');
        }
        foreach ($this->option('origin') as $origin) {
            if (! filter_var($origin, FILTER_VALIDATE_URL)) {
                return $this->failWith("Invalid --origin: {$origin}");
            }
        }
        $rate = (int) $this->option('rate');
        if ($rate < 1 || $rate > 10000) {
            return $this->failWith('--rate must be between 1 and 10000.');
        }

        [$client, $key, $secret] = ExternalApiClient::issue($name, $webhookUrl, $this->option('origin'), $rate);

        $this->info("Client #{$client->id} \"{$client->name}\" created.");
        $this->newLine();
        $this->line("  X-API-Key:       <comment>{$key}</comment>");
        if ($secret) {
            $this->line("  Webhook secret:  <comment>{$secret}</comment>");
            $this->line("  Webhook URL:     {$webhookUrl}");
        }
        $this->newLine();
        $this->warn('Store these now — they are shown only once and cannot be recovered.');

        return self::SUCCESS;
    }

    private function listClients(): int
    {
        $rows = ExternalApiClient::query()->orderBy('id')->get()->map(fn (ExternalApiClient $c) => [
            $c->id, $c->name, $c->key_prefix.'…', $c->webhook_url ?: '—', implode(', ', $c->allowed_origins ?? []) ?: '—',
            $c->rate_limit_per_minute, $c->last_used_at?->toDateTimeString() ?? '—',
            $c->revoked_at ? 'revoked '.$c->revoked_at->toDateTimeString() : 'active',
        ]);

        $this->table(['ID', 'Name', 'Key', 'Webhook', 'Origins', 'Rate/min', 'Last used', 'Status'], $rows);

        return self::SUCCESS;
    }

    private function revoke(): int
    {
        $target = trim((string) $this->argument('target'));
        if ($target === '') {
            return $this->failWith('Client id or key prefix is required: external-api:client revoke 3');
        }
        $client = ctype_digit($target)
            ? ExternalApiClient::find((int) $target)
            : ExternalApiClient::where('key_prefix', substr($target, 0, 12))->first();

        if (! $client) {
            return $this->failWith("Client not found: {$target}");
        }
        if ($client->isRevoked()) {
            $this->info("Client #{$client->id} was already revoked.");

            return self::SUCCESS;
        }

        $client->update(['revoked_at' => now()]);
        $this->info("Client #{$client->id} \"{$client->name}\" revoked — its key stops working immediately and it gets no more webhooks.");

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
