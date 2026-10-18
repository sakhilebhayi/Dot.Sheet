<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Blade does not touch an attribute value on a plain HTML tag, so `:class="{ a: $this->tab === 'x' }"`
 * or `:disabled="$loading"` reach the browser as written. Alpine evaluates them as JavaScript, where
 * `$this` and `$loading` do not exist: one "is not defined" console error per element on every
 * render, and the class or disabled state never applied. A Livewire property is `$wire.name`.
 */
class LivewireAlpineExpressionsTest extends TestCase
{
    private const ALPINE_MAGICS = ['wire', 'el', 'refs', 'dispatch', 'event', 'nextTick', 'watch', 'store', 'root', 'data', 'id'];

    public function test_no_livewire_view_uses_a_php_variable_inside_an_alpine_expression(): void
    {
        $offences = [];

        foreach (glob(resource_path('views/livewire/*.blade.php')) as $view) {
            $source = file_get_contents($view);
            // Server-side output is fine inside a value: drop {{ ... }} and {!! ... !!} first.
            $source = preg_replace(['/\{\{.*?\}\}/s', '/\{!!.*?!!\}/s'], '', $source);

            preg_match_all('/\s(:[\w.:-]+|x-(?!on|data)[\w.:-]+|@[\w.:-]+|x-on:[\w.:-]+)="([^"]*)"/', $source, $matches, PREG_SET_ORDER);

            foreach ($matches as [, $attribute, $expression]) {
                preg_match_all('/\$(\w+)/', $expression, $variables);
                foreach ($variables[1] as $name) {
                    if (! in_array($name, self::ALPINE_MAGICS, true)) {
                        $offences[] = basename($view)." {$attribute}=\"{$expression}\" uses \${$name}";
                    }
                }
            }
        }

        $this->assertSame([], $offences, "Use \$wire.property in Alpine expressions:\n".implode("\n", $offences));
    }

    public function test_the_ai_panels_read_livewire_state_through_wire(): void
    {
        $analysis = file_get_contents(resource_path('views/livewire/ai-analysis-panel.blade.php'));
        $this->assertSame(6, substr_count($analysis, "\$wire.active_tab === '"));
        $this->assertStringContainsString(':disabled="$wire.loading"', $analysis);

        $this->assertStringContainsString("'opacity-50 cursor-not-allowed': \$wire.loading", file_get_contents(resource_path('views/livewire/ai-formula-modal.blade.php')));

        $query = file_get_contents(resource_path('views/livewire/ai-natural-language-query.blade.php'));
        $this->assertStringContainsString('@keydown.enter.meta="$wire.submitQuery()"', $query);
        $this->assertStringContainsString('@keydown.enter.ctrl="$wire.submitQuery()"', $query);
    }
}
