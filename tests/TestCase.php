<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Request;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        // A test run in production mode applies the trusted-hosts check, which Symfony keeps in a static:
        // cleared so it never reaches a later test's request host.
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    /**
     * The bytes of an .xlsx file holding these rows, as a person's spreadsheet program would save it.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function xlsxBytes(array $rows): string
    {
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->fromArray($rows);

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        return tap((string) file_get_contents($path), fn () => @unlink($path));
    }
}
