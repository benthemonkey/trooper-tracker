<?php

declare(strict_types=1);

namespace TroopTracker\Plugins\Xenforo\Tests;

use App\Models\Event;
use App\Models\Organization;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use TroopTracker\Plugins\Xenforo\Database\Seeders\MidwestGarrisonForumSeeder;
use TroopTracker\Plugins\Xenforo\Models\EventThread;
use TroopTracker\Plugins\Xenforo\Models\OrganizationForum;

class MidwestGarrisonForumSeederTest extends TestCase
{
    use RefreshDatabase;
    use UsesXenforoPlugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableXenforo();
    }

    public function test_it_sets_forums_and_groups_on_the_garrison_squad_and_clubs(): void
    {
        $legion = Organization::factory()->asOrganization()->withName('501st Legion')->create();
        $garrison = Organization::factory()->asRegion()->withParent($legion)->withName('Midwest Garrison')->create();
        $squad = Organization::factory()->asUnit()->withParent($garrison)->withName('332nd Blurrg Squad')->create();
        $rebels = Organization::factory()->asOrganization()->withName('Rebel Legion')->create();

        (new MidwestGarrisonForumSeeder)->run();

        $forum = OrganizationForum::forOrganization($garrison->id);
        $this->assertSame(222, $forum->event_forum_id);
        $this->assertSame(332, $forum->archive_forum_id);
        $this->assertSame(1504, $forum->active_group_id);

        $this->assertSame(1503, OrganizationForum::forOrganization($squad->id)->active_group_id);
        $this->assertSame(836, OrganizationForum::forOrganization($rebels->id)->active_group_id);
        //  visitors from other garrisons are members of the 501st itself
        $legion_forum = OrganizationForum::forOrganization($legion->id);
        $this->assertSame(1510, $legion_forum->active_group_id);
        $this->assertNull($legion_forum->event_forum_id);
    }

    public function test_it_links_imported_events_to_their_legacy_threads(): void
    {
        Schema::create('events', function (Blueprint $table): void
        {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('thread_id')->default(0);
            $table->unsignedBigInteger('post_id')->default(0);
        });

        $with_thread = Event::factory()->create();
        $without_thread = Event::factory()->create();

        DB::table('events')->insert([
            ['id' => $with_thread->id, 'thread_id' => 501, 'post_id' => 601],
            ['id' => $without_thread->id, 'thread_id' => 0, 'post_id' => 0],
            //  a legacy row that wasn't imported
            ['id' => 999999, 'thread_id' => 502, 'post_id' => 602],
        ]);

        (new MidwestGarrisonForumSeeder)->run();

        $thread = EventThread::forEvent($with_thread->id);
        $this->assertSame(501, $thread->thread_id);
        $this->assertSame(601, $thread->post_id);
        $this->assertNull(EventThread::forEvent($without_thread->id));
        $this->assertSame(1, EventThread::query()->count());
    }

    public function test_it_does_nothing_for_threads_without_the_legacy_table(): void
    {
        (new MidwestGarrisonForumSeeder)->run();

        $this->assertSame(0, EventThread::query()->count());
    }
}
