<?php

namespace Modules\Core\Features\RabbitMQ\Connection;

use Illuminate\Contracts\Config\Repository;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;

/**
 * The process's one connection to the broker, opened from config('core.rabbitmq') (.env
 * RABBITMQ_*) on first use and reopened if it dropped. Bound as a singleton.
 */
class RabbitMQConnection
{
    private ?AbstractConnection $connection = null;

    public function __construct(private readonly Repository $config) {}

    public function connection(): AbstractConnection
    {
        if ($this->connection === null || ! $this->connection->isConnected()) {
            $this->connection = AMQPConnectionFactory::create($this->connectionConfig());
        }

        return $this->connection;
    }

    public function openChannel(): AMQPChannel
    {
        return $this->connection()->channel();
    }

    public function heartbeatSeconds(): int
    {
        return (int) $this->setting('heartbeat');
    }

    public function close(): void
    {
        if ($this->connection?->isConnected()) {
            $this->connection->close();
        }

        $this->connection = null;
    }

    private function connectionConfig(): AMQPConnectionConfig
    {
        $config = new AMQPConnectionConfig;
        $config->setHost($this->setting('host'));
        $config->setPort($this->setting('port'));
        $config->setUser($this->setting('user'));
        $config->setPassword($this->setting('password'));
        $config->setVhost($this->setting('vhost'));
        $config->setIsSecure($this->setting('secure'));
        $config->setConnectionTimeout($this->setting('connection_timeout'));
        $config->setReadTimeout($this->setting('read_write_timeout'));
        $config->setWriteTimeout($this->setting('read_write_timeout'));
        $config->setHeartbeat($this->setting('heartbeat'));
        $config->setKeepalive($this->setting('keepalive'));

        return $config;
    }

    private function setting(string $key): mixed
    {
        return $this->config->get("core.rabbitmq.{$key}");
    }
}
