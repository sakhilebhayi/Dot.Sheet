<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use ZipArchive;

/**
 * EA-1 (S-03): checks a spreadsheet file before anything loads it, so a small file cannot make the server
 * build an enormous sheet in memory. Excel and ODS files are zip archives: what they unpack to is checked
 * first, then the size of the sheet the request would load. Measured on PHP 8.5: about 0.7 KB of memory per
 * cell for a values-only read, so the defaults keep a read well inside the host's 128 MB.
 */
final class ImportGuard
{
    public const TOO_BIG_MESSAGE = 'That file unpacks to more than this can read at once. Split it into smaller files, or save only the sheets you need.';

    private const READERS = ['xlsx' => IOFactory::READER_XLSX, 'xls' => IOFactory::READER_XLS, 'ods' => IOFactory::READER_ODS];

    private const MAX_ENTRIES = 5000;

    /**
     * @throws ImportRefused
     */
    public static function checkArchive(string $path, string $extension): void
    {
        if (! in_array($extension, ['xlsx', 'ods'], true)) {
            return;
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return; // not an archive: the reader will refuse it as unreadable
        }

        try {
            $limit = max(1, (int) config('sheet.import_max_unpacked_mb', 64)) * 1024 * 1024;
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new ImportRefused(self::TOO_BIG_MESSAGE);
            }

            $unpacked = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $unpacked += (int) ($zip->statIndex($i)['size'] ?? 0);
                if ($unpacked > $limit) {
                    throw new ImportRefused(self::TOO_BIG_MESSAGE);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * The first sheet's name and the number of cells its used area spans (rows x columns), read without loading it.
     *
     * @return array{name: string, cells: int}
     *
     * @throws ImportRefused
     */
    public static function firstSheet(string $path, string $extension): array
    {
        self::checkArchive($path, $extension);

        $info = IOFactory::createReader(self::READERS[$extension])->listWorksheetInfo($path)[0] ?? null;
        if ($info === null) {
            return ['name' => '', 'cells' => 0];
        }

        $cells = (int) $info['totalRows'] * (int) $info['totalColumns'];
        $limit = (int) config('sheet.import_first_sheet_max_cells', 100000);
        if ($limit > 0 && $cells > $limit) {
            throw new ImportRefused(sprintf(
                'The first sheet spans %s cells from its first to its last used cell; this can import up to %s at once. Remove empty rows and columns far from the data, or split the sheet.',
                number_format($cells), number_format($limit),
            ));
        }

        return ['name' => (string) $info['worksheetName'], 'cells' => $cells];
    }
}
