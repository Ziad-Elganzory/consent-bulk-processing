<?php

namespace App\Infrastructure\Messaging\Publishing\Exceptions;

use RuntimeException;

/**
 * The broker could not take the message right now (unreachable, timed out, or
 * it rejected the publish). The message itself is not at fault, so the relay
 * does not count this against the message's attempt limit.
 */
class TransientPublishFailure extends RuntimeException {}
