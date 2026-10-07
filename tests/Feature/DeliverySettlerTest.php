<?php

use App\Domains\BulkImport\Messages\ParseRequested;
use App\Infrastructure\Messaging\Consuming\DeliverySettler;
use App\Infrastructure\Messaging\Consuming\Settlement;
use App\Infrastructure\Messaging\Contracts\MessageHandler;
use App\Infrastructure\Messaging\Protocol\MessageEnvelope;
use App\Infrastructure\Messaging\Publishing\ConfirmedPublisher;
use App\Infrastructure\Messaging\Publishing\Exceptions\TransientPublishFailure;
use App\Infrastructure\Messaging\Topology\QueueDefinition;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class DeliverySettlerTestHandler implements MessageHandler
{
    /** @var list<string> */
    public array $handled = [];

    /** @var list<string> */
    public array $failed = [];

    public ?Throwable $throw = null;

    public bool $failedHookThrows = false;

    public function handle(MessageEnvelope $envelope): void
    {
        $this->handled[] = $envelope->messageId;

        if ($this->throw !== null) {
            throw $this->throw;
        }
    }

    public function failed(MessageEnvelope $envelope, Throwable $exception): void
    {
        $this->failed[] = $exception->getMessage();

        if ($this->failedHookThrows) {
            throw new RuntimeException('failed hook broke');
        }
    }
}

function delivery(AMQPChannel $channel, ?string $body = null, ?int $attempt = null): AMQPMessage
{
    $properties = ['message_id' => 'message-1', 'content_type' => 'application/json'];

    if ($attempt !== null) {
        $properties['application_headers'] = new AMQPTable([DeliverySettler::ATTEMPT_HEADER => $attempt]);
    }

    $envelope = MessageEnvelope::make(new ParseRequested('import-1', 'consent/import-1/source/source.csv'), 'import-1');
    $message = new AMQPMessage($body ?? $envelope->toJson(), $properties);
    $message->setChannel($channel);
    $message->setDeliveryInfo(7, false, '', 'jobs');

    return $message;
}

beforeEach(function (): void {
    $this->handler = new DeliverySettlerTestHandler;
    app()->instance(DeliverySettlerTestHandler::class, $this->handler);

    $published = $this->published = new ArrayObject;
    $this->mock(ConfirmedPublisher::class, fn (MockInterface $mock) => $mock->shouldReceive('publish')
        ->andReturnUsing(function (AMQPMessage $message, string $exchange, string $routingKey) use ($published): void {
            $published[] = ['message' => $message, 'exchange' => $exchange, 'routing_key' => $routingKey];
        }));

    $this->channel = Mockery::mock(AMQPChannel::class);
    $this->queue = new QueueDefinition('jobs', 'x', ['k'], maxAttempts: 3, retryDelaySeconds: 30, prefetch: 1, handler: DeliverySettlerTestHandler::class);
});

function copiedHeaders(array $published): array
{
    return $published['message']->get_properties()['application_headers']->getNativeData();
}

it('acknowledges a message the handler handled', function (): void {
    $this->channel->shouldReceive('basic_ack')->once()->with(7, false);

    $settlement = app(DeliverySettler::class)->settle($this->queue, delivery($this->channel));

    expect($settlement)->toBe(Settlement::Acknowledged)
        ->and($this->handler->handled)->toHaveCount(1)
        ->and($this->published)->toHaveCount(0);
});

it('sends a failed message to the retry queue with the next attempt, then acknowledges it', function (): void {
    $this->handler->throw = new RuntimeException('boom');
    $this->channel->shouldReceive('basic_ack')->once()->with(7, false);
    $original = delivery($this->channel);

    $settlement = app(DeliverySettler::class)->settle($this->queue, $original);

    $copy = $this->published[0];
    expect($settlement)->toBe(Settlement::RetryScheduled)
        ->and($this->published)->toHaveCount(1)
        ->and($copy['exchange'])->toBe('')
        ->and($copy['routing_key'])->toBe('jobs.retry')
        ->and($copy['message']->getBody())->toBe($original->getBody())
        ->and($copy['message']->get('message_id'))->toBe('message-1')
        ->and(copiedHeaders($copy))->toBe([DeliverySettler::ATTEMPT_HEADER => 2, DeliverySettler::FAILURE_HEADER => 'boom'])
        ->and($this->handler->failed)->toBe([]);
});

it('moves a message to the dead queue on its last attempt and calls the failed hook', function (): void {
    $this->handler->throw = new RuntimeException('boom');
    $this->channel->shouldReceive('basic_ack')->once()->with(7, false);

    $settlement = app(DeliverySettler::class)->settle($this->queue, delivery($this->channel, attempt: 3));

    expect($settlement)->toBe(Settlement::MovedToDeadQueue)
        ->and($this->published[0]['routing_key'])->toBe('jobs.dead')
        ->and(copiedHeaders($this->published[0])[DeliverySettler::ATTEMPT_HEADER])->toBe(3)
        ->and($this->handler->failed)->toBe(['boom']);
});

it('still acknowledges a dead-lettered message when the failed hook throws', function (): void {
    $this->handler->throw = new RuntimeException('boom');
    $this->handler->failedHookThrows = true;
    $this->channel->shouldReceive('basic_ack')->once()->with(7, false);

    $settlement = app(DeliverySettler::class)->settle($this->queue, delivery($this->channel, attempt: 3));

    expect($settlement)->toBe(Settlement::MovedToDeadQueue);
});

it('rejects a message that is not a valid envelope without running the handler', function (string $body): void {
    $this->channel->shouldReceive('basic_reject')->once()->with(7, false);

    $settlement = app(DeliverySettler::class)->settle($this->queue, delivery($this->channel, body: $body));

    expect($settlement)->toBe(Settlement::Unreadable)
        ->and($this->handler->handled)->toBe([])
        ->and($this->published)->toHaveCount(0);
})->with([
    'not json' => ['{not json'],
    'not an envelope' => ['{"hello": "world"}'],
    'unknown type' => ['{"message_id":"m","type":"consent.unknown","correlation_id":"c","occurred_at":"2026-10-06T10:00:00+00:00","data":{}}'],
]);

it('does not acknowledge when the retry copy cannot be published', function (): void {
    $this->handler->throw = new RuntimeException('boom');
    $this->mock(ConfirmedPublisher::class, fn (MockInterface $mock) => $mock->shouldReceive('publish')->andThrow(new TransientPublishFailure('RabbitMQ unavailable')));
    $this->channel->shouldNotReceive('basic_ack');

    app(DeliverySettler::class)->settle($this->queue, delivery($this->channel));
})->throws(TransientPublishFailure::class);
