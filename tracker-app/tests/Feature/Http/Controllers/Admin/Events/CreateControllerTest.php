<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Admin\Events;

use App\Models\EventType;
use App\Models\Trooper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoke_displays_create_event_page_for_admin(): void
    {
        $trooper = Trooper::factory()->asAdministrator()->create();

        $response = $this->actingAs($trooper)->get(route('admin.events.create'));

        $response->assertOk();
        $response->assertViewIs('pages.admin.events.create');
    }

    public function test_invoke_lists_offered_types_in_order_with_their_help(): void
    {
        $trooper = Trooper::factory()->asAdministrator()->create();
        EventType::query()->update([EventType::SORT_ORDER => 50]);
        EventType::where(EventType::SLUG, 'wedding')->update([EventType::SORT_ORDER => 1]);
        EventType::where(EventType::SLUG, 'disney')->update([EventType::IS_ACTIVE => false]);

        $response = $this->actingAs($trooper)->get(route('admin.events.create'));

        $response->assertOk();
        $response->assertSeeInOrder(['value="wedding"', 'value="armorparty"'], false);
        $response->assertDontSee('value="disney"', false);
        $response->assertSee("x-data=\"{ event_type: 'wedding'", false);
        $response->assertDontSee('@js(', false);
    }

    public function test_invoke_preselects_regular_with_the_seeded_types(): void
    {
        $trooper = Trooper::factory()->asAdministrator()->create();

        $response = $this->actingAs($trooper)->get(route('admin.events.create'));

        $response->assertOk();
        $response->assertSee("x-data=\"{ event_type: 'regular'", false);
        $response->assertSeeInOrder(['value="regular"', 'value="armorparty"'], false);
    }

    public function test_invoke_requires_authentication(): void
    {
        $response = $this->get(route('admin.events.create'));

        $response->assertRedirect(route('auth.login'));
    }
}
