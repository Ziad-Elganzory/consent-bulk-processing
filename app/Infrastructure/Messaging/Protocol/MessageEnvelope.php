<?php

namespace App\Infrastructure\Messaging\Protocol;

use App\Infrastructure\Messaging\Topology\MessagingRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * What actually travels through RabbitMQ: a typed message plus the identity needed to
 * trace and deduplicate it. The body is this object as JSON:
 *
 *   {"message_id", "type", "correlation_id", "occurred_at", "data": {...}}
 */
final readonly class MessageEnvelope
{
    public function __construct(
        public string $messageId,
        public string $correlationId,
        public CarbonImmutable $occurredAt,
        public MessageContract $message,
    ) {
        MessageFields::nonEmpty($this->messageId, 'message_id');
        MessageFields::nonEmpty($this->correlationId, 'correlation_id');
    }

    /**
     * Wraps a new message, giving it a fresh id unless one is passed in.
     */
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
     * Reads a received body. The registry supplies the message class for its type.
     *
     * @throws JsonException when the body is not JSON
     * @throws InvalidArgumentException when it is JSON but not a valid envelope
     */
    public static function fromJson(string $json, MessagingRegistry $registry): self
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('The message body is not a JSON object.');
        }

        return self::fromArray($decoded, $registry);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException when the payload is not a valid envelope
     */
    public static function fromArray(array $payload, MessagingRegistry $registry): self
    {
        if (! is_array($payload['data'] ?? null)) {
            throw new InvalidArgumentException('Field [data] must be an object.');
        }

        $fields = new MessageFields($payload);
        $occurredAt = $fields->text('occurred_at');

        try {
            $occurredAt = CarbonImmutable::parse($occurredAt);
        } catch (Throwable) {
            throw new InvalidArgumentException('Field [occurred_at] is not a valid date.');
        }

        return new self(
            messageId: $fields->text('message_id'),
            correlationId: $fields->text('correlation_id'),
            occurredAt: $occurredAt,
            message: $registry->message($fields->text('type'), $payload['data']),
        );
    }
}
