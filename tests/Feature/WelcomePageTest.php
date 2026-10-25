<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Render-level guards for the two welcome-page nav bugs that survived the
 * Tailwind 3 -> 4 move. The computed colours themselves were checked in a
 * headless browser at 375px and 1280px (paper-on-paper menu links, a
 * see-through scrolled bar); these pin the markup and CSS that fix them.
 */
class WelcomePageTest extends TestCase
{
    public function test_the_welcome_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Dot.Sheet');
    }

    public function test_the_scrolled_nav_has_a_paper_background(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString(
            '.nav-scrolled { background-color: color-mix(in srgb, var(--paper) 95%, transparent); }',
            $html,
        );
    }

    public function test_the_mobile_menu_links_are_coloured_by_rules_that_beat_the_nav_state(): void
    {
        $html = $this->get('/')->getContent();

        // The panel is solid paper; its links must not inherit `.nav-top a` (paper).
        $this->assertStringContainsString('class="mobile-menu md:hidden', $html);
        $this->assertStringContainsString('.nav-top .mobile-menu a, .nav-scrolled .mobile-menu a { color: var(--slate); }', $html);
        $this->assertStringNotContainsString('hover:text-[var(--ink)]!', $html);
    }
}
