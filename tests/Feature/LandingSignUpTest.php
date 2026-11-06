<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

/** The landing page's buttons lead somewhere a new person can start. */
class LandingSignUpTest extends TestCase
{
    use RefreshDatabase;

    private const NOTE = 'Accounts are made on InfoDot';

    public function test_with_sign_up_closed_the_buttons_go_to_infodot_and_say_so(): void
    {
        config(['fortify.features' => array_values(array_diff(config('fortify.features'), [Features::registration()]))]);

        $page = $this->get('/')->assertOk()->assertSee(self::NOTE)->getContent();

        $this->assertSame(3, substr_count($page, 'href="https://infodot.co.za/register"'));
        $this->assertStringNotContainsString(url('/register'), $page);
    }

    public function test_with_sign_up_open_the_buttons_stay_here(): void
    {
        config(['fortify.features' => array_values(array_unique([...config('fortify.features'), Features::registration()]))]);

        $this->get('/')->assertSee('href="'.route('register').'"', false)->assertDontSee(self::NOTE);
    }

    public function test_someone_signed_in_sees_no_sign_up(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create())->get('/')
            ->assertOk()->assertDontSee(self::NOTE)->assertDontSee('infodot.co.za/register');
    }
}
