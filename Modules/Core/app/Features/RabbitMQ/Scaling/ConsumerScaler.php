<?php

namespace Modules\Core\Features\RabbitMQ\Scaling;

use Modules\Core\Features\RabbitMQ\Constants\MessagingConstants;

/**
 * Decides, for one queue on one supervisor tick, how many consumers to start or stop.
 *
 * Limits apply to the queue's consumers across all supervisors (the broker's count), so
 * several worker containers together never exceed maxConsumers. Scaling up is gradual —
 * at most SCALE_UP_STEP per tick while messages wait — and scaling down is one consumer per
 * tick once the queue has been empty for the cooldown.
 */
final class ConsumerScaler
{
    /**
     * @param  int  $localConsumers  consumers this supervisor runs for the queue (the only ones it can stop)
     * @param  int  $freeProcessSlots  processes this supervisor may still start (its own cap)
     * @param  int  $idleSeconds  how long the queue has had no ready message
     * @return int consumers to start (> 0) or stop (< 0)
     */
    public static function change(QueueLoad $load,
        ConsumerScaling $scaling,
        int $localConsumers,
        int $freeProcessSlots,
        int $idleSeconds,
    ): int {
        // A consumer this supervisor just started may not be connected yet.
        $consumers = max($load->consumers, $localConsumers);

        $wanted = match (true) {
            $load->readyMessages > 0 => $consumers + min($load->readyMessages, MessagingConstants::SCALE_UP_STEP),
            $idleSeconds >= $scaling->scaleDownCooldownSeconds() => $consumers - 1,
            default => $consumers,
        };

        $target = min(max($wanted, $scaling->minConsumers()), $scaling->maxConsumers());
        $change = $target - $consumers;

        return $change > 0
            ? min($change, max(0, $freeProcessSlots))
            : max($change, -$localConsumers);
    }
}
