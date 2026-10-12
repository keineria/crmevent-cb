<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_theme_and_mode_are_saved_on_the_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings/appearance', ['theme' => 'marea'])->assertSessionHasNoErrors();
        $this->actingAs($user)->patch('/settings/appearance', ['appearance' => 'dark'])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('marea', $user->theme);
        $this->assertSame('dark', $user->appearance);

        // The first paint already carries the account's choice, on any device.
        $this->actingAs($user)->get('/settings/appearance')
            ->assertOk()
            ->assertSee('data-theme="marea"', false)
            ->assertSee('class="dark"', false);
    }

    public function test_unknown_themes_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings/appearance', ['theme' => 'neon', 'appearance' => 'sepia'])
            ->assertSessionHasErrors(['theme', 'appearance']);
        $this->assertNull($user->refresh()->theme);
    }
}
