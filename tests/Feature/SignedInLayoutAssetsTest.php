<?php

namespace Tests\Feature;

use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * Every signed-in page uses layouts/app.blade.php (the public share page has its own script-free layout). It
 * must load the Vite-built stylesheet and script, not the Tailwind Play CDN and a
 * second, CDN copy of Alpine (Livewire already ships its own: a second one made
 * every Alpine component that touches Livewire fail). Tests run with Vite faked
 * out, so the dev-server "hot" file stands in for a build.
 */
class SignedInLayoutAssetsTest extends TestCase
{
    use RefreshDatabase;

    private function assertBuiltAssetsOnly(string $html): void
    {
        $this->assertStringContainsString('resources/css/signed-in.css', $html);
        $this->assertStringContainsString('resources/js/app.js', $html);
        $this->assertStringNotContainsString('cdn.tailwindcss.com', $html);
        $this->assertStringNotContainsString('unpkg.com', $html);
        $this->assertStringNotContainsString('alpinejs', $html);
        $this->assertStringNotContainsString('tailwind.config', $html);
    }

    /**
     * @param  callable(): string  $request  returns the response body
     */
    private function withHotFile(callable $request): string
    {
        $hot = tempnam(sys_get_temp_dir(), 'vite-hot');
        file_put_contents($hot, 'http://localhost:5173');

        try {
            $this->withVite();
            Vite::useHotFile($hot);

            return $request();
        } finally {
            unlink($hot);
        }
    }

    public function test_dashboard_loads_the_built_assets_and_no_cdn_tailwind_or_alpine(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->withHotFile(fn () => $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent());

        $this->assertBuiltAssetsOnly($html);
    }

    public function test_the_spreadsheet_editor_loads_the_built_assets_and_no_cdn_tailwind_or_alpine(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $spreadsheet = Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $user->id,
            'team_id' => $user->currentTeam->id,
            'name' => 'Workbook',
        ]);

        $html = $this->withHotFile(fn () => $this->actingAs($user)
            ->get(route('spreadsheets.show', $spreadsheet))
            ->assertOk()
            ->getContent());

        $this->assertBuiltAssetsOnly($html);
    }

    public function test_the_public_share_page_loads_the_built_stylesheet_and_no_script(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $owner->id,
            'team_id' => $owner->currentTeam->id,
            'name' => 'Shared workbook',
            'public_enabled' => true,
            'public_token' => 'shared-token-123',
        ]);

        // Opened without signing in: the page has its own layout, with the stylesheet but no script.
        $html = $this->withHotFile(fn () => $this->get(route('spreadsheets.public', 'shared-token-123'))
            ->assertOk()
            ->getContent());

        $this->assertStringContainsString('resources/css/signed-in.css', $html);
        $this->assertStringNotContainsString('resources/js/app.js', $html);
        $this->assertStringNotContainsString('cdn.tailwindcss.com', $html);
        $this->assertStringNotContainsString('unpkg.com', $html);
    }

    public function test_the_layout_source_has_no_cdn_tailwind_or_alpine(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString("@vite(['resources/css/signed-in.css', 'resources/js/app.js'])", $layout);
        $this->assertStringContainsString('@livewireScripts', $layout);
        $this->assertStringNotContainsString('cdn.tailwindcss.com', $layout);
        $this->assertStringNotContainsString('unpkg.com', $layout);
        $this->assertStringNotContainsString('alpinejs', $layout);
    }

    public function test_the_signed_in_entry_has_no_preflight_and_no_plugins(): void
    {
        $css = file_get_contents(resource_path('css/signed-in.css'));

        // Preflight and the forms/typography plugins would restyle every page on this layout.
        $this->assertStringNotContainsString("@import 'tailwindcss'", $css);
        $this->assertStringNotContainsString("@import 'tailwindcss/preflight.css'", $css);
        $this->assertStringNotContainsString('@plugin', $css);
        $this->assertStringContainsString("@import 'tailwindcss/theme.css'", $css);
        $this->assertStringContainsString("@import 'tailwindcss/utilities.css'", $css);
    }
}
