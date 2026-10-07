<?php

namespace App\Console\Commands;

use App\Infrastructure\Messaging\Consuming\ConsumerLimits;
use App\Infrastructure\Messaging\Consuming\QueueConsumer;
use App\Infrastructure\Messaging\Consuming\Settlement;
use App\Infrastructure\Messaging\Topology\MessagingRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use PhpAmqpLib\Message\AMQPMessage;

#[Signature('rabbitmq:consume
    {queue : Name of a declared queue}
    {--max-messages=0 : Stop after settling this many messages (0 = no limit)}
    {--max-time=0 : Stop after running this many seconds (0 = no limit)}
    {--memory=128 : Stop once memory use passes this many megabytes}')]
#[Description('Run the handler of one queue until stopped or a limit is reached.')]
class ConsumeQueue extends Command
{
    public function handle(MessagingRegistry $registry, QueueConsumer $consumer): int
    {
        try {
            $queue = $registry->queue($this->argument('queue'));
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($queue->handler === null) {
            $this->components->error("Queue [{$queue->name}] has no handler yet.");

            return self::FAILURE;
        }

        // A stop signal lets the message in hand finish first.
        $this->trap([SIGTERM, SIGINT, SIGQUIT], fn () => $consumer->stop());

        $this->components->info("Consuming {$queue->name}: prefetch {$queue->prefetch}, up to {$queue->maxAttempts} attempts per message.");

        $consumer->run(
            $queue,
            new ConsumerLimits(
                maxMessages: (int) $this->option('max-messages'),
                maxSeconds: (int) $this->option('max-time'),
                maxMemoryMegabytes: (int) $this->option('memory'),
            ),
            fn (AMQPMessage $delivery, Settlement $settlement) => $this->line(sprintf(
                '%s  %s  %s',
                now()->toDateTimeString(),
                $delivery->has('message_id') ? $delivery->get('message_id') : '-',
                $settlement->value,
            )),
        );

        return self::SUCCESS;
    }
}
