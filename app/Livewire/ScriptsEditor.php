<?php

namespace App\Livewire;

use App\Models\Spreadsheet;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ScriptsEditor extends Component
{
    use AuthorizesRequests;

    public $spreadsheet_id;

    protected ?Spreadsheet $spreadsheet = null;

    public $scripts = [];

    public $scriptMacros = [];

    public $selectedScriptId = null;

    public $scriptName = '';

    public $scriptBody = "// Return action list\nreturn [\n  { type: 'set', cell: 'A1', value: 'Hello' }\n];";

    public $macroName = '';

    public $status = '';

    protected $listeners = ['macro-recorded' => 'saveRecordedMacro'];

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
        $this->saveToSettings();
        $this->status = 'Script saved.';
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

        $this->saveToSettings();
        $this->status = 'Script deleted.';
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
        if (empty($actions)) {
            return;
        }

        $name = trim($this->macroName) !== '' ? trim($this->macroName) : ('Macro '.now()->format('H:i:s'));
        $this->scriptMacros = collect($this->scriptMacros)
            ->prepend([
                'id' => (string) str()->uuid(),
                'name' => $name,
                'actions' => $actions,
                'created_at' => now()->toISOString(),
            ])
            ->values()
            ->toArray();

        $this->macroName = '';
        $this->saveToSettings();
        $this->status = 'Macro saved.';
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
        $this->scriptMacros = collect($this->scriptMacros)
            ->reject(fn ($m) => $m['id'] === $id)
            ->values()
            ->toArray();

        $this->saveToSettings();
        $this->status = 'Macro deleted.';
    }

    protected function loadFromSettings(): void
    {
        $settings = $this->spreadsheet->settings ?? [];
        $this->scripts = $settings['scripts'] ?? [];
        $this->scriptMacros = $settings['macros'] ?? [];
    }

    protected function saveToSettings(): void
    {
        $settings = $this->spreadsheet->settings ?? [];
        $settings['scripts'] = $this->scripts;
        $settings['macros'] = $this->scriptMacros;
        $this->spreadsheet->settings = $settings;
        $this->spreadsheet->save();
    }
}
