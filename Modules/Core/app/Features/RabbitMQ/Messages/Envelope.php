<?php

namespace Modules\Core\Features\RabbitMQ\Messages;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use JsonException;
use Modules\Core\Features\RabbitMQ\Constants\MessagingConstants;
use Modules\Core\Features\RabbitMQ\Contracts\Message;
use Modules\Core\Features\RabbitMQ\Exceptions\InvalidEnvelope;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * A message as it travels: the event's routing key and payload, plus its identity.
 *
 * The JSON body never changes across deliveries:
 *
 *     {"message_id": "...", "routing_key": "bulk.chunk.ready", "occurred_at": "...", "payload": {...}}
 *
 * The delivery attempt and the last error change on every retry, so they travel as headers.
 */
final readonly class Envelope
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  int  $attempt  1 on the first delivery
     */
    public function __construct(
        public string $messageId,
        public string $routingKey,
        public array $payload,
        public CarbonImmutable $occurredAt,
        public int $attempt = 1,
        public ?string $lastError = null,
    ) {}

    public static function wrap(Message $message): self
    {
        return new self(
            messageId: (string) Str::uuid7(),
            routingKey: $message->routingKey(),
            payload: $message->toPayload(),
            occurredAt: CarbonImmutable::now(),
        );
    }

    /**
     * @throws InvalidEnvelope
     */
    public static function fromAmqpMessage(AMQPMessage $amqpMessage): self
    {
        try {
            $body = json_decode($amqpMessage->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidEnvelope::because('the body is not JSON', $exception);
        }

        if (! is_array($body)
            || ! is_string($body['message_id'] ?? null)
            || ! is_string($body['routing_key'] ?? null)
            || ! is_string($body['occurred_at'] ?? null)
            || ! is_array($body['payload'] ?? null)) {
            throw InvalidEnvelope::because('message_id, routing_key, occurred_at or payload is missing');
        }

        try {
            $occurredAt = CarbonImmutable::parse($body['occurred_at']);
        } catch (Throwable $exception) {
            throw InvalidEnvelope::because('occurred_at is not a date', $exception);
        }

        $headers = $amqpMessage->has('application_headers')
            ? $amqpMessage->get('application_headers')->getNativeData()
            : [];

        return new self(
            messageId: $body['message_id'],
            routingKey: $body['routing_key'],
            payload: $body['payload'],
            occurredAt: $occurredAt,
            attempt: (int) ($headers[MessagingConstants::ATTEMPT_HEADER] ?? 1),
            lastError: $headers[MessagingConstants::ERROR_HEADER] ?? null,
        );
    }

    public function toAmqpMessage(): AMQPMessage
    {
        $headers = [MessagingConstants::ATTEMPT_HEADER => $this->attempt];

        if ($this->lastError !== null) {
            $headers[MessagingConstants::ERROR_HEADER] = $this->lastError;
        }

        return new AMQPMessage(
            json_encode([
                'message_id' => $this->messageId,
                'routing_key' => $this->routingKey,
                'occurred_at' => $this->occurredAt->toIso8601ZuluString('microsecond'),
                'payload' => $this->payload,
            ], JSON_THROW_ON_ERROR),
            [
                'content_type' => MessagingConstants::CONTENT_TYPE,
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $this->messageId,
                'type' => $this->routingKey,
                'timestamp' => $this->occurredAt->getTimestamp(),
                'application_headers' => new AMQPTable($headers),
            ],
        );
    }

    /**
     * The same message for its next delivery, after $error failed this one.
     */
    public function forNextAttempt(string $error): self
    {
        return $this->copyForDelivery(attempt: $this->attempt + 1, lastError: $error);
    }

    /**
     * The same message, marked with the error that used up its last attempt.
     */
    public function withFinalError(string $error): self
    {
        return $this->copyForDelivery(attempt: $this->attempt, lastError: $error);
    }

    private function copyForDelivery(int $attempt, string $lastError): self
    {
        return new self(
            messageId: $this->messageId,
            routingKey: $this->routingKey,
            payload: $this->payload,
            occurredAt: $this->occurredAt,
            attempt: $attempt,
            lastError: Str::limit($lastError, MessagingConstants::ERROR_TEXT_MAX_LENGTH),
        );
    }
}
