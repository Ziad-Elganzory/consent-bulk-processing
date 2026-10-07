<?php

namespace Modules\Core\Features\RabbitMQ\Console;

use Illuminate\Console\Command;
use Modules\Core\Features\RabbitMQ\Connection\RabbitMQConnection;
use Throwable;

class CheckRabbitMQConnection extends Command
{
    protected $signature = 'rabbitmq:check';

    protected $description = 'Connect to RabbitMQ with the RABBITMQ_* connection from .env and open a channel';

    public function handle(RabbitMQConnection $connection): int
    {
        $config = config('core.rabbitmq');

        $this->components->twoColumnDetail('Host', "{$config['host']}:{$config['port']}");
        $this->components->twoColumnDetail('Virtual host', $config['vhost']);
        $this->components->twoColumnDetail('User', $config['user']);

        try {
            $serverProperties = $connection->connection()->getServerProperties();
            $this->components->task('Open a channel', fn () => $connection->openChannel()->close());
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $connection->close();
        }

        $this->components->twoColumnDetail('Server', ($serverProperties['product'][1] ?? 'RabbitMQ').' '.($serverProperties['version'][1] ?? ''));
        $this->components->info('RabbitMQ is working.');

        return self::SUCCESS;
    }
}
