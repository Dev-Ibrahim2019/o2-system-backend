<?php

namespace App\Services\Integration;

use App\Models\IntegrationOutbox;
use App\Support\Integration\IntegrationReference;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class IntegrationOutboxWriter
{
    public function record(
        string $eventType,
        string $aggregateType,
        string $aggregateRef,
        array $payload,
        int $schemaVersion = 1,
        ?CarbonInterface $occurredAt = null,
        ?CarbonInterface $availableAt = null,
    ): IntegrationOutbox {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $eventType) !== 1) {
            throw new InvalidArgumentException('Event type must use canonical dot-separated machine names.');
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $aggregateType) !== 1) {
            throw new InvalidArgumentException('Aggregate type must use a canonical machine name.');
        }

        $expectedPrefix = match ($aggregateType) {
            'order' => IntegrationReference::ORDER_PREFIX,
            'customer' => IntegrationReference::CUSTOMER_PREFIX,
            default => throw new InvalidArgumentException('Aggregate type is not supported by the F-01A writer.'),
        };

        if (! IntegrationReference::isValid($aggregateRef, $expectedPrefix)) {
            throw new InvalidArgumentException('Aggregate reference must be a typed integration reference.');
        }

        if ($schemaVersion < 1) {
            throw new InvalidArgumentException('Schema version must be at least 1.');
        }

        $occurredAt ??= now();

        return IntegrationOutbox::create([
            'outbox_ref' => IntegrationReference::outbox(),
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_ref' => $aggregateRef,
            'payload' => $payload,
            'schema_version' => $schemaVersion,
            'occurred_at' => $occurredAt,
            'available_at' => $availableAt ?? $occurredAt,
            'attempt_count' => 0,
        ]);
    }
}
