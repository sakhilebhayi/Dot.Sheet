<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * With a single Alpine on the signed-in pages (Livewire's own), the editor
 * re-renders after every cell action, and two template habits that were hidden
 * while the CDN copy of Alpine broke Livewire's DOM updates start to show:
 * a child component whose view opens with an HTML comment is re-rendered as the
 * text `< wire:id="...">` (the root tag is read from the first tag), and an
 * element a script shows or hides with a class is reset to the server's markup.
 */
class LivewireViewRootsTest extends TestCase
{
    public function test_no_livewire_view_starts_with_an_html_comment(): void
    {
        $views = glob(resource_path('views/livewire/*.blade.php'));

        $this->assertNotEmpty($views);

        foreach ($views as $view) {
            $this->assertStringStartsNotWith(
                '<!--',
                ltrim(file_get_contents($view)),
                basename($view).' opens with an HTML comment; use a Blade comment ({{-- --}}) so the root element comes first.',
            );
        }
    }

    public function test_script_toggled_elements_in_the_editor_are_ignored_by_livewire_morphing(): void
    {
        $view = file_get_contents(resource_path('views/livewire/show-spreadsheet.blade.php'));

        foreach ([
            'spreadsheetContextMenu', 'analysisPanel', 'nlqModal', 'shortcutsModal', 'helpModal',
            'bulkPasteProgress', 'importProgress', 'activeUsers', 'remoteCursorsLayer',
        ] as $id) {
            $this->assertMatchesRegularExpression(
                '/<(div|aside) id="'.$id.'" wire:ignore /',
                $view,
                "#{$id} is shown or hidden by a script, so it needs wire:ignore.",
            );
        }
    }
}
