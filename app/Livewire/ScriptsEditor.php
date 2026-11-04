<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsSheetState;
use App\Models\Spreadsheet;
use App\Services\SheetMacros;
use App\Services\WriteLock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ScriptsEditor extends Component
{
    use AuthorizesRequests;
    use GuardsSheetState;

    #[Locked]
    public $spreadsheet_id;

    protected ?Spreadsheet $spreadsheet = null;

    // Locked: the lists are only ever changed by the actions below. A browser that could set
    // them would get its own code stored the next time anything (a macro) is saved.
    #[Locked]
    public $scripts = [];

    #[Locked]
    public $scriptMacros = [];

    public $selectedScriptId = null;

    public $scriptName = '';

    public $scriptBody = "// Return action list\nreturn [\n  { type: 'set', cell: 'A1', value: 'Hello' }\n];";

    public $macroName = '';

    public $status = '';

    protected $listeners = ['macro-recorded' => 'saveRecordedMacro'];

    protected function clientWritable(): array
    {
        return [
            'macroName',
            'scriptBody',
            'scriptName',
        ];
    }

    protected function sheetAbility(): string
    {
        return 'update';
    }

    public function mount($spreadsheet_id)
    {
        $this->spreadsheet_id = $spreadsheet_id;
        $this->spreadsheet = Spreadsheet::findOrFail($spreadsheet_id);
        $this->authorize('update', $this->spreadsheet);
        $this->loadFromSettings();
    }

    public function hydrate(): void
    {
        if ($this->spreadsheet_id) {
            $this->spreadsheet = Spreadsheet::findOrFail($this->spreadsheet_id);

            // Checked on every request, not only at mount: a person demoted meanwhile keeps the component open.
            $this->authorize('update', $this->spreadsheet);
        }
    }

    public function render()
    {
        return view('livewire.scripts-editor', ['scriptsEnabled' => $this->scriptsEnabled()]);
    }

    /**
     * Saved scripts are code. Off unless config('sheet.scripts_enabled') says otherwise (see
     * config/sheet.php); every script action checks it again on the server, because a
     * Livewire action can be called without the button that would normally trigger it.
     */
    protected function scriptsEnabled(): bool
    {
        return (bool) config('sheet.scripts_enabled');
    }

    protected function refuseWhenScriptsAreOff(): bool
    {
        if ($this->scriptsEnabled()) {
            return false;
        }

        $this->status = 'Scripts are turned off on this server.';

        return true;
    }

    public function newScript(): void
    {
        if ($this->refuseWhenScriptsAreOff()) {
            return;
        }

        $this->selectedScriptId = null;
        $this->scriptName = '';
        $this->scriptBody = "// Return action list\nreturn [\n  { type: 'set', cell: 'A1', value: 'Hello' }\n];";
    }

    public function loadScript(string $id): void
    {
        if ($this->refuseWhenScriptsAreOff()) {
            return;
        }

        $script = collect($this->scripts)->firstWhere('id', $id);
        if (! $script) {
            return;
        }

        $this->selectedScriptId = $script['id'];
        $this->scriptName = $script['name'];
        $this->scriptBody = $script['body'];
    }

    public function saveScript(): void
    {
        if ($this->refuseWhenScriptsAreOff()) {
            return;
        }

        $this->validate([
            'scriptName' => 'required|string|min:2|max:80',
            'scriptBody' => 'required|string|min:5|max:20000',
        ]);

        $scripts = collect($this->scripts);
        if ($this->selectedScriptId) {
            $scripts = $scripts->map(function ($s) {
                if ($s['id'] !== $this->selectedScriptId) {
                    return $s;
                }
                $s['name'] = $this->scriptName;
                $s['body'] = $this->scriptBody;
                $s['updated_at'] = now()->toISOString();

                return $s;
            });
        } else {
            $new = [
                'id' => (string) str()->uuid(),
                'name' => $this->scriptName,
                'body' => $this->scriptBody,
                'updated_at' => now()->toISOString(),
            ];
            $scripts->prepend($new);
            $this->selectedScriptId = $new['id'];
        }

        $this->scripts = $scripts->values()->toArray();
        $this->status = $this->saveToSettings() ? 'Script saved.' : WriteLock::BUSY_MESSAGE;
    }

    public function deleteScript(string $id): void
    {
        if ($this->refuseWhenScriptsAreOff()) {
            return;
        }

        $this->scripts = collect($this->scripts)
            ->reject(fn ($s) => $s['id'] === $id)
            ->values()
            ->toArray();

        if ($this->selectedScriptId === $id) {
            $this->newScript();
        }

        $this->status = $this->saveToSettings() ? 'Script deleted.' : WriteLock::BUSY_MESSAGE;
    }

    public function dispatchRunScript(): void
    {
        if ($this->refuseWhenScriptsAreOff()) {
            return;
        }

        if (! $this->scriptBody) {
            return;
        }

        $this->dispatch('run-script-in-sandbox', [
            'name' => $this->scriptName ?: 'Untitled Script',
            'body' => $this->scriptBody,
            'context' => [
                'selectedCell' => [
                    'row' => 0,
                    'col' => 0,
                    'ref' => 'A1',
                ],
            ],
        ]);
    }

    public function saveRecordedMacro($payload): void
    {
        $data = is_array($payload) ? $payload : [];
        $actions = $data['actions'] ?? [];
        if (empty($actions) || ! is_array($actions)) {
            return;
        }

        $saved = WriteLock::guard(fn () => app(SheetMacros::class)->add((int) $this->spreadsheet_id, (string) $this->macroName, $actions) ?? true, fn () => false);

        $this->macroName = '';
        $this->reloadMacros();
        $this->status = $saved === true ? 'Macro saved.' : ($saved === false ? WriteLock::BUSY_MESSAGE : $saved);
    }

    public function playMacro(string $id): void
    {
        $macro = collect($this->scriptMacros)->firstWhere('id', $id);
        if (! $macro) {
            return;
        }

        $this->dispatch('apply-script-actions', [
            'source' => 'macro',
            'actions' => $macro['actions'],
        ]);
    }

    public function deleteMacro(string $id): void
    {
        $deleted = WriteLock::guard(function () use ($id) {
            app(SheetMacros::class)->remove((int) $this->spreadsheet_id, $id);

            return true;
        }, fn () => false);

        $this->reloadMacros();
        $this->status = $deleted ? 'Macro deleted.' : WriteLock::BUSY_MESSAGE;
    }

    protected function reloadMacros(): void
    {
        $this->scriptMacros = app(SheetMacros::class)->of(Spreadsheet::findOrFail($this->spreadsheet_id));
    }

    protected function loadFromSettings(): void
    {
        $settings = $this->spreadsheet->settings ?? [];
        $this->scripts = $settings['scripts'] ?? [];
        $this->scriptMacros = $settings['macros'] ?? [];
    }

    protected function saveToSettings(): bool
    {
        // Under the write lock, and never with the flag off: then no action may add or change a script,
        // whatever this component holds in memory. Macros have their own way in, SheetMacros.
        $scripts = $this->scripts;

        return WriteLock::guard(
            function () use ($scripts) {
                if ($this->scriptsEnabled()) {
                    Spreadsheet::updateSettings((int) $this->spreadsheet_id, fn (array $settings) => [...$settings, 'scripts' => $scripts]);
                }

                return true;
            },
            fn () => false
        );
    }
}
