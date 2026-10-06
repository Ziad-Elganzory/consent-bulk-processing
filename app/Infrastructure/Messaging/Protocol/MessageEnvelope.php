<?php

namespace App\Infrastructure\Messaging\Protocol;

use App\Infrastructure\Messaging\Protocol\Messages\ParseRequested;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * The common structure of every message published to RabbitMQ.
 *
 * Messages carry IDs and object-storage references only, never CSV contents.
 */
final readonly class MessageEnvelope
{
    public function __construct(
        public string $messageId,
        public string $correlationId,
        public CarbonImmutable $occurredAt,
        public MessageContract $message,
    ) {
        MessageData::assertNonEmptyString($this->messageId, 'message_id');
        MessageData::assertNonEmptyString($this->correlationId, 'correlation_id');
    }

    public static function make(MessageContract $message, string $correlationId, ?string $messageId = null): self
    {
        return new self(
            messageId: $messageId ?? (string) Str::uuid(),
            correlationId: $correlationId,
            occurredAt: CarbonImmutable::now(),
            message: $message,
        );
    }

    /**
     * The message type, which is also the routing key.
     */
    public function type(): string
    {
        return $this->message::type();
    }

    /**
     * @return array{message_id: string, type: string, correlation_id: string, occurred_at: string, data: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'type' => $this->type(),
            'correlation_id' => $this->correlationId,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'data' => $this->message->data(),
        ];
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     * @throws InvalidArgumentException when the JSON is not a valid envelope
     */
    public static function fromJson(string $json): self
    {
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('The message envelope must be a JSON object.');
        }

        return self::fromArray($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException when the payload is not a valid envelope
     */
    public static function fromArray(array $payload): self
    {
        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw new InvalidArgumentException('The [data] field must be an array.');
        }

        $occurredAt = MessageData::requiredString($payload, 'occurred_at');

        try {
            $occurredAt = CarbonImmutable::parse($occurredAt);
        } catch (Throwable) {
            throw new InvalidArgumentException('The [occurred_at] field must be a valid date.');
        }

        $type = MessageData::requiredString($payload, 'type');

        $message = match ($type) {
            ParseRequested::type() => ParseRequested::fromData($data),
            default => throw new InvalidArgumentException("Unsupported message type [{$type}]."),
        };

        return new self(
            messageId: MessageData::requiredString($payload, 'message_id'),
            correlationId: MessageData::requiredString($payload, 'correlation_id'),
            occurredAt: $occurredAt,
            message: $message,
        );
    }
}
