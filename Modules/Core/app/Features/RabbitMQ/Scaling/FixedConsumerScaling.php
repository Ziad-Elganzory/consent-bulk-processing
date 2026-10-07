<?php

namespace Modules\Core\Features\RabbitMQ\Scaling;

use InvalidArgumentException;
use Modules\Core\Features\RabbitMQ\Constants\MessagingConstants;

final readonly class FixedConsumerScaling implements ConsumerScaling
{
    public function __construct(
        private int $minConsumers = MessagingConstants::DEFAULT_MIN_CONSUMERS,
        private int $maxConsumers = MessagingConstants::DEFAULT_MAX_CONSUMERS,
        private int $scaleDownCooldownSeconds = MessagingConstants::DEFAULT_SCALE_DOWN_COOLDOWN_SECONDS,
    ) {
        if ($minConsumers < 0 || $maxConsumers < max(1, $minConsumers)) {
            throw new InvalidArgumentException('Consumer scaling needs 0 <= min <= max and max >= 1.');
        }
    }

    public function minConsumers(): int
    {
        return $this->minConsumers;
    }

    public function maxConsumers(): int
    {
        return $this->maxConsumers;
    }

    public function scaleDownCooldownSeconds(): int
    {
        return $this->scaleDownCooldownSeconds;
    }
}
