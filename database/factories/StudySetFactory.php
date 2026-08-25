<?php

namespace Database\Factories;

use App\Models\StudySet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudySet>
 */
class StudySetFactory extends Factory
{
    /**
     * Defaults match the model's own $attributes: private and authored. Tests
     * that care about visibility or source pass it explicitly rather than
     * relying on a state method, so the intent is visible at the call site.
     *
     * cards_count is left to the model default (0) — it is a denormalised
     * counter, and a factory inventing a value for it would be a set whose
     * counter disagrees with its cards, i.e. exactly what scopeMiscounted()
     * exists to find.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => StudySet::uniqueSlug(),
            'user_id' => User::factory(),
            'title' => fake()->words(3, true),
            'description' => null,
            'visibility' => StudySet::VISIBILITY_PRIVATE,
            'source' => StudySet::SOURCE_AUTHORED,
        ];
    }
}
