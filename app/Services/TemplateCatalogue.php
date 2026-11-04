<?php

namespace App\Services;

/**
 * The workbook templates: blueprints in resources/templates, one file each, grouped for the page. See
 * WorkbookBuilder for what a blueprint holds.
 */
class TemplateCatalogue
{
    public const GROUPS = ['Business', 'People', 'Projects', 'Operations', 'Mining', 'Personal'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $all = null;

    /**
     * @return array<string, array<string, mixed>> by key
     */
    public function all(): array
    {
        if ($this->all === null) {
            $this->all = [];
            foreach (glob(resource_path('templates/*.php')) ?: [] as $file) {
                $template = require $file;
                $this->all[$template['key']] = $template;
            }
            ksort($this->all);
        }

        return $this->all;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return array<string, list<array<string, mixed>>> in the page's order
     */
    public function grouped(): array
    {
        $groups = array_fill_keys(self::GROUPS, []);
        foreach ($this->all() as $template) {
            $groups[$template['group']][] = $template;
        }

        return array_filter($groups);
    }
}
