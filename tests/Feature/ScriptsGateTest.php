<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * User scripts (JavaScript run in the browser of whoever clicked Run) lived in the classic editor and went with it in
 * EA-6. Nothing in the shared bundle can start a script worker any more; macros record edits and play them back.
 */
class ScriptsGateTest extends TestCase
{
    public function test_the_shared_bundle_has_no_script_sandbox_and_no_worker_file(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringNotContainsString('new Worker', $js);
        $this->assertStringNotContainsString('runSpreadsheetScriptSandbox', $js);
        $this->assertFileDoesNotExist(resource_path('js/workers/script-sandbox.worker.js'));
        // EA-4: no chart library, tour or dead formula parser in the shared bundle either.
        $this->assertStringNotContainsString('chart.js', $js);
        $this->assertStringNotContainsString('shepherd', $js);
        $this->assertFileDoesNotExist(resource_path('js/formula-parser.js'));
    }
}
