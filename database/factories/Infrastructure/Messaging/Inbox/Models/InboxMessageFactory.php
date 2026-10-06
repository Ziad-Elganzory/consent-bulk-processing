<?php

namespace Database\Factories\Infrastructure\Messaging\Inbox\Models;

use App\Infrastructure\Messaging\Inbox\Models\InboxMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxMessage>
 */
class InboxMessageFactory extends Factory
{
    protected $model = InboxMessage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'consumer_name' => fake()->word(),
            'message_id' => fake()->uuid(),
        ];
    }
}
