<?php

namespace Tests\Feature;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\SeededTestCase;

/**
 * Every screen renders, for the role that is meant to see it.
 *
 * These run against the seeded database rather than a fresh one: the whole
 * product is the seeded price corpus, and a screen that only renders when
 * there is no data is not a screen anyone will look at.
 */
class ScreensTest extends SeededTestCase
{
    private function member(): User
    {
        return User::where('email', 'chidi@student.unilag.ng')->firstOrFail();
    }

    private function ops(): User
    {
        return User::where('email', 'a.okafor@moneypilot.ng')->firstOrFail();
    }

    private function owner(): User
    {
        return User::where('email', 't.bakare@moneypilot.ng')->firstOrFail();
    }

    public function test_the_homepage_and_login_are_public(): void
    {
        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
    }

    public function test_the_app_is_closed_to_strangers(): void
    {
        $this->get('/app')->assertRedirect('/login');
        $this->get('/console')->assertRedirect('/login');
    }

    #[DataProvider("memberScreens")]
    public function test_member_screens_render(string $uri): void
    {
        $this->actingAs($this->member())->get($uri)->assertOk();
    }

    public static function memberScreens(): array
    {
        return array_map(fn ($u) => [$u], [
            '/app', '/app/budget', '/app/spend', '/app/prices',
            '/app/prices/rice', '/app/plan', '/app/goals', '/app/health', '/app/coach', '/app/profile',
        ]);
    }

    public function test_an_infeasible_member_still_gets_a_dashboard(): void
    {
        $amaka = User::where('email', 'amaka.eze@gmail.com')->firstOrFail();

        $this->actingAs($amaka)->get('/app')
            ->assertOk()
            ->assertSee('does not add up');
    }

    #[DataProvider("consoleScreens")]
    public function test_console_screens_render_for_operations(string $uri): void
    {
        $this->actingAs($this->ops())->get($uri)->assertOk();
    }

    public static function consoleScreens(): array
    {
        return array_map(fn ($u) => [$u], [
            '/console', '/console/moderation', '/console/coverage',
            '/console/contributors', '/console/index', '/console/members',
        ]);
    }

    /** The gate is real: hiding the link is not access control. */
    public function test_a_member_cannot_reach_the_console(): void
    {
        $this->actingAs($this->member())->get('/console')->assertForbidden();
    }

    public function test_only_the_owner_sees_the_business_dashboard(): void
    {
        $this->actingAs($this->owner())->get('/console/business')->assertOk();
        $this->actingAs($this->ops())->get('/console/business')->assertForbidden();
    }
}
