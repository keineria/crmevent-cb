<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('300#######'),
            'country' => 'Colombia',
            'city' => fake()->city(),
            'source' => Contact::SOURCE_IMPORT,
        ];
    }

    public function mailable(): static
    {
        return $this->state(['marketing_consent' => true, 'consent_at' => now(), 'consent_source' => 'Prueba']);
    }
}
