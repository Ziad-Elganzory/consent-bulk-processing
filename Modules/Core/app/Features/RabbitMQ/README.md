# RabbitMQ

A Core feature that lets modules send messages to each other through RabbitMQ, so slow work runs in background workers instead of the request.

A module does three things:

1. **Declares** its exchanges and queues in one `ModuleMessaging` class.
2. **Publishes** by recording a message in the **outbox**, inside its own database transaction.
3. **Handles** the messages of each queue in a `MessageHandler`.

The feature does the rest: creating the exchanges and queues on the broker, publishing the outbox, consuming, retrying, dead-lettering, and scaling the consumers.

## Contents

- [How it works](#how-it-works)
- [Setup](#setup)
- [Configuration](#configuration)
- [Example: an Orders module](#example-an-orders-module)
- [Running it](#running-it)
- [Retries and the dead queue](#retries-and-the-dead-queue)
- [Scaling consumers](#scaling-consumers)
- [Commands](#commands)
- [Rules for handlers](#rules-for-handlers)
- [Data model](#data-model)
- [Tests](#tests)
- [Files](#files)

## How it works

```
Module action (inside DB::transaction)
  └─ Outbox::record(new OrderPlaced(...)) ──► outbox_messages row
                                                   │
           rabbitmq:relay-outbox ◄─────────────────┘  publishes in order, waits for the broker's confirm
                  │
                  ▼
        exchange "orders.events" (topic)
                  │ routing key "order.placed"
                  ▼
        queue "orders.send-receipt" ◄──── after the retry delay ──── orders.send-receipt.retry
                  │                                                         ▲
    rabbitmq:work starts and scales rabbitmq:consume processes              │ handler threw,
                  │                                                         │ attempts left
                  ▼                                                         │
           SendReceiptHandler::handle() ── ok ──► ack                       │
                                        ── throws ──────────────────────────┘
                                        ── throws on the last attempt ──► orders.send-receipt.dead
```

### Why an outbox

Publishing straight to the broker from an action has two failure modes: the database commits but the publish fails (the event is lost), or the publish succeeds but the transaction rolls back (an event for something that never happened). Recording the message in the same transaction as the change means both happen or neither does. The relay publishes it afterwards.

### Delivery guarantees

Every step confirms before it moves on: the relay marks a message published only after the broker confirms it, and the consumer acknowledges a failed message only after its copy reached the retry or dead queue. So a crash can cause a message to be **delivered twice**, but never lost. Handlers must therefore be idempotent (see [Rules for handlers](#rules-for-handlers)).

## Setup

The feature is enabled in `Modules/Core/config/config.php`:

```php
'features' => [
    // ...
    RabbitMQServiceProvider::class,
],
```

It needs the `sockets` PHP extension (`php-amqplib`) and RabbitMQ 3.10 or newer (quorum queues with at-least-once dead-lettering).

1. **Set the connection** in `.env` (see [Configuration](#configuration)), then check it:

   ```sh
   php artisan rabbitmq:check
   ```

2. **Run the migrations.** They create the `outbox_messages` table:

   ```sh
   php artisan migrate
   ```

3. **Declare the topology** once per deploy, after the migrations. It creates every module's exchanges, queues and bindings, and changes nothing when they already exist:

   ```sh
   php artisan rabbitmq:declare-topology
   ```

4. **Start the workers**, see [Running it](#running-it).

## Configuration

`config/rabbitmq.php`, read as `config('core.rabbitmq.*')`, takes everything from `.env`:

| Variable | Default | Meaning |
| --- | --- | --- |
| `RABBITMQ_HOST` | `127.0.0.1` | Broker host |
| `RABBITMQ_PORT` | `5672` | Broker port (`5671` for TLS) |
| `RABBITMQ_USER` | `guest` | User |
| `RABBITMQ_PASSWORD` | `guest` | Password |
| `RABBITMQ_VHOST` | `/` | Virtual host |
| `RABBITMQ_SECURE` | `false` | TLS (amqps) |
| `RABBITMQ_CONNECTION_TIMEOUT` | `3` | Seconds to wait for the connection |
| `RABBITMQ_READ_WRITE_TIMEOUT` | `130` | Seconds; **at least twice the heartbeat**, or php-amqplib refuses to connect |
| `RABBITMQ_HEARTBEAT` | `60` | Seconds; `0` disables heartbeats |
| `RABBITMQ_KEEPALIVE` | `false` | TCP keepalive |

The workers are long-running processes: restart them (and run `php artisan config:cache` if you cache the config) after changing a value.

The fixed rules of the messaging layer (queue type, retry defaults, relay batch size, supervisor limits) are constants in `Constants/MessagingConstants.php`.

## Example: an Orders module

An `Orders` module publishes `order.placed` when an order is created, and sends the customer a receipt in the background.

### 1. Declare the messaging

One class lists the module's exchanges and the queues it consumes. Names live as constants on it.

```php
<?php

namespace Modules\Orders\Messaging;

use Modules\Core\Features\RabbitMQ\Contracts\ModuleMessaging;
use Modules\Core\Features\RabbitMQ\Scaling\FixedConsumerScaling;
use Modules\Core\Features\RabbitMQ\Topology\ExchangeDefinition;
use Modules\Core\Features\RabbitMQ\Topology\ExchangeType;
use Modules\Core\Features\RabbitMQ\Topology\QueueDefinition;
use Modules\Orders\Handlers\SendReceiptHandler;

final class OrdersMessaging implements ModuleMessaging
{
    // Exchange
    public const string EVENTS_EXCHANGE = 'orders.events';

    // Events (routing keys)
    public const string ORDER_PLACED = 'order.placed';

    // Queues
    public const string SEND_RECEIPT_QUEUE = 'orders.send-receipt';

    public function exchanges(): array
    {
        return [
            new ExchangeDefinition(self::EVENTS_EXCHANGE, ExchangeType::Topic),
        ];
    }

    public function queues(): array
    {
        return [
            new QueueDefinition(
                name: self::SEND_RECEIPT_QUEUE,
                exchange: self::EVENTS_EXCHANGE,
                routingKeys: [self::ORDER_PLACED],
                handler: SendReceiptHandler::class,
                maxAttempts: 5,
                retryDelaySeconds: 60,
                scaling: new FixedConsumerScaling(minConsumers: 1, maxConsumers: 3),
            ),
        ];
    }
}
```

Register it on the module's service provider (a `ModuleServiceProvider`):

```php
class OrdersServiceProvider extends ModuleServiceProvider
{
    protected ?string $messaging = OrdersMessaging::class;
}
```

Check what was registered:

```sh
php artisan rabbitmq:topology
```

### 2. Define the message

A small readonly class. Its payload must be JSON-serialisable and hold only what the handler needs, usually ids.

```php
<?php

namespace Modules\Orders\Messages;

use Modules\Core\Features\RabbitMQ\Contracts\Message;
use Modules\Orders\Messaging\OrdersMessaging;

final readonly class OrderPlaced implements Message
{
    public function __construct(public int $orderId) {}

    public function exchange(): string
    {
        return OrdersMessaging::EVENTS_EXCHANGE;
    }

    public function routingKey(): string
    {
        return OrdersMessaging::ORDER_PLACED;
    }

    public function toPayload(): array
    {
        return ['order_id' => $this->orderId];
    }

    public static function fromPayload(array $payload): static
    {
        return new self($payload['order_id']);
    }
}
```

### 3. Publish it

Record the message in the same transaction as the change:

```php
<?php

namespace Modules\Orders\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Features\RabbitMQ\Publishing\Outbox;
use Modules\Orders\Messages\OrderPlaced;
use Modules\Orders\Models\Order;

class PlaceOrder
{
    public function __construct(private readonly Outbox $outbox) {}

    public function handle(array $data): Order
    {
        return DB::transaction(function () use ($data): Order {
            $order = Order::create($data);

            $this->outbox->record(new OrderPlaced($order->id));

            return $order;
        });
    }
}
```

`record()` only writes a row. The message reaches the broker when `rabbitmq:relay-outbox` picks it up, normally within a second.

### 4. Handle it

```php
<?php

namespace Modules\Orders\Handlers;

use Illuminate\Support\Facades\Log;
use Modules\Core\Features\RabbitMQ\Contracts\MessageHandler;
use Modules\Core\Features\RabbitMQ\Messages\Envelope;
use Modules\Orders\Messages\OrderPlaced;
use Modules\Orders\Models\Order;
use Modules\Orders\Notifications\OrderReceipt;
use Throwable;

class SendReceiptHandler implements MessageHandler
{
    public function handle(Envelope $envelope): void
    {
        $message = OrderPlaced::fromPayload($envelope->payload);
        $order = Order::findOrFail($message->orderId);

        // The same message can arrive twice: skip work that is already done.
        if ($order->receipt_sent_at !== null) {
            return;
        }

        $order->customer->notify(new OrderReceipt($order));
        $order->update(['receipt_sent_at' => now()]);
    }

    public function failed(Envelope $envelope, Throwable $exception): void
    {
        // Every attempt failed; the message is now in orders.send-receipt.dead.
        Log::error('Receipt not sent', [
            'order_id' => $envelope->payload['order_id'],
            'error' => $exception->getMessage(),
        ]);
    }
}
```

The handler is resolved from the container, so its constructor can take dependencies.

### 5. Deploy

```sh
php artisan migrate
php artisan rabbitmq:declare-topology   # creates orders.events and the 3 orders.send-receipt queues
```

The running `rabbitmq:work` picks up the new queue only after a restart (it reads the declared queues when it starts).

## Running it

Three long-running processes, each under a process manager that restarts it (Docker, Supervisor, systemd):

| Process | Command | How many |
| --- | --- | --- |
| Outbox relay | `php artisan rabbitmq:relay-outbox` | 1 or more (rows are locked with `SKIP LOCKED`, so replicas never publish a message twice) |
| Workers | `php artisan rabbitmq:work` (or `--queue=orders.*` for some queues) | 1 per worker container |
| Scheduler | `php artisan schedule:work` | Exactly 1 (deletes published outbox rows after 7 days) |

All three stop cleanly on `SIGTERM`: the relay finishes its batch, consumers finish their message.

With docker-compose:

```yaml
  workers:
    <<: *app
    command: ["php", "artisan", "rabbitmq:work", "--queue=orders.*"]

  outbox-relay:
    <<: *app
    command: ["php", "artisan", "rabbitmq:relay-outbox"]

  migrate:
    <<: *app
    profiles: ["tools"]
    restart: "no"
    command: ["sh", "-c", "php artisan migrate --force && php artisan rabbitmq:declare-topology"]
```

In development you can run one queue by hand instead of `rabbitmq:work`:

```sh
php artisan rabbitmq:consume orders.send-receipt
```

## Retries and the dead queue

Each `QueueDefinition` creates three durable quorum queues:

| Queue | Purpose |
| --- | --- |
| `orders.send-receipt` | The work queue the consumers read. |
| `orders.send-receipt.retry` | No consumers. A failed message waits here `retryDelaySeconds`, then the broker moves it back to the work queue. |
| `orders.send-receipt.dead` | Messages that used up `maxAttempts`, kept for inspection. Nothing consumes it. |

For each delivery, the consumer does this:

| What happened | Result |
| --- | --- |
| `handle()` returned | Acknowledged. |
| `handle()` threw, attempts left | Copied to `.retry` with the attempt number + 1 and the error, then acknowledged. |
| `handle()` threw on the last attempt | Copied to `.dead` with the error, `failed()` called, then acknowledged. |
| The body is not a valid envelope | Rejected straight to `.dead`, without calling the handler. |

The attempt number and last error travel as the `x-attempt` and `x-last-error` headers; the JSON body never changes.

A retry goes straight to its own queue, so other queues bound to the same routing key don't receive it twice.

## Scaling consumers

`rabbitmq:work` checks every queue every 3 seconds and starts or stops `rabbitmq:consume` processes, within the queue's `scaling`:

- **Messages waiting:** starts up to 2 more consumers per check, up to `maxConsumers`.
- **No message waiting for `scaleDownCooldownSeconds`:** stops one consumer, down to `minConsumers`.

Limits count consumers across **all** worker containers (the broker's count), so two containers together still respect `maxConsumers`. Each supervisor also caps its own processes with `--max-processes` (default 20).

| Scaling | Behaviour |
| --- | --- |
| Not set | `new FixedConsumerScaling()`: exactly 1 consumer. |
| `new FixedConsumerScaling(minConsumers: 1, maxConsumers: 3)` | 1 normally, up to 3 while messages wait. |
| `new FixedConsumerScaling(minConsumers: 0, maxConsumers: 2)` | None while the queue is empty. |
| Your own `ConsumerScaling` | Read on every check, so limits stored in settings apply without a restart. |

Each consumer exits after an hour (`CONSUMER_MAX_SECONDS`) and is started again fresh.

## Commands

| Command | Purpose |
| --- | --- |
| `rabbitmq:check` | Connect with the `.env` settings, open a channel, show the server version. |
| `rabbitmq:topology` | List every declared exchange and queue, with its module, handler, attempts and retry delay. |
| `rabbitmq:declare-topology` | Create the exchanges, queues and bindings. Fails (and still declares the rest) when a queue already exists with other arguments. |
| `rabbitmq:relay-outbox [--batch=100] [--once]` | Publish the outbox. |
| `rabbitmq:work [--queue=*] [--max-processes=20] [--max-time=0]` | Run and scale consumers for the selected queues (all when omitted; patterns like `orders.*`). |
| `rabbitmq:consume {queue} [--max-messages=0] [--max-time=0] [--memory=128]` | Consume one queue. Usually started by `rabbitmq:work`. |

## Rules for handlers

- **Idempotent.** The same message can arrive more than once. Check whether the work is done, or use `$envelope->messageId` (the same on every delivery) to remember what was handled.
- **Throw to retry.** Any exception sends the message to the retry queue. Don't catch errors you want retried.
- **Small payloads.** Send ids, not models or files; read the current state in the handler.
- **`failed()` must not throw.** If it does, the error is reported but the message stays in the dead queue.

## Things to know

- **One failing outbox row blocks the ones after it.** The relay publishes in order and retries the failing row every second; check `failed_attempts` and `last_error` in `outbox_messages` when messages stop arriving.
- **A routing key no queue is bound to is dropped by the broker**, and its outbox row is still marked published. Run `rabbitmq:declare-topology` before the relay.
- **Changing a declared queue** (type, retry delay) can't be done by declaring it again: the broker refuses with `PRECONDITION_FAILED`. Delete the queue on the broker and declare it again.
- **Dead queues are not emptied automatically.** Inspect them in the RabbitMQ management UI.

## Data model

| Table | Purpose |
| --- | --- |
| `outbox_messages` | Messages waiting to be published: `id` (UUID v7, also the `message_id`, sorted in recording order), `exchange`, `routing_key`, `payload` (JSON), `failed_attempts`, `last_error`, `created_at`, `published_at`. Published rows are pruned after 7 days. |

## Tests

```sh
php artisan test Modules/Core/tests/Feature/RabbitMQ Modules/Core/tests/Unit/RabbitMQ
```

The broker tests (`RabbitMQBrokerTest`) run against the `RABBITMQ_*` connection and are skipped when no broker answers. They only use `core-test.*` names and remove them afterwards.

Test fixtures in `Modules/Core/tests/Fixtures/RabbitMQ/`:

- `InMemoryMessagePublisher`: bind it as `MessagePublisher` to test publishing without a broker.
- `DemoMessaging`, `DemoHappened`, `RecordingHandler`: a minimal module, message and handler.
- `RecordingProcessPool`: a process pool that only counts, for scaling tests.

## Files

```
RabbitMQ/
├── RabbitMQServiceProvider.php     feature entry point (singletons, commands, outbox pruning)
├── config/rabbitmq.php             the connection, from .env (see Configuration)
├── Connection/RabbitMQConnection   the process's one broker connection
├── Console/                        the six rabbitmq:* commands
├── Constants/MessagingConstants    queue rules, retry defaults, relay and supervisor limits
├── Consuming/                      DeliveryProcessor (ack / retry / dead), DeliveryOutcome
├── Contracts/                      Message, MessageHandler, MessagePublisher, ModuleMessaging
├── database/migrations/            outbox_messages
├── Exceptions/                     InvalidEnvelope, InvalidMessagingDefinition, MessageNotPublished
├── Messages/Envelope               a message as it travels (JSON body + headers)
├── Models/OutboxMessage            an outbox row
├── Publishing/                     Outbox (record + relay), AmqpMessagePublisher (confirmed publishing)
├── Scaling/                        consumer scaling for rabbitmq:work
└── Topology/                       ExchangeDefinition, ExchangeType, QueueDefinition, MessagingRegistry
```
