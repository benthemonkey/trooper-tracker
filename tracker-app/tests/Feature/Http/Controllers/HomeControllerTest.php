<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Models\Trooper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoke_displays_home_page_for_guests(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertViewIs('pages.home');
    }

    public function test_invoke_shows_the_configured_navbar_icon(): void
    {
        $this->get(route('home'))
            ->assertSee(url('img/icons/troop-tracker-32x32.png'), false);

        config(['tracker.navbar_icon' => 'https://forum.example.com/emblem.png']);

        $this->get(route('home'))
            ->assertSee('https://forum.example.com/emblem.png', false)
            ->assertDontSee('troop-tracker-32x32.png', false);
    }

    public function test_invoke_redirects_authenticated_troopers_to_events_list(): void
    {
        $trooper = Trooper::factory()->asActive()->create();

        $response = $this->actingAs($trooper)->get(route('home'));

        $response->assertRedirect(route('events.list'));
    }
}
