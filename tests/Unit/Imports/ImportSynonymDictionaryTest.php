<?php

namespace Tests\Unit\Imports;

use App\Services\Imports\EmployeeImportService;
use App\Services\Imports\SpreadsheetReader;
use App\Services\Imports\XlsxWriter;
use App\Services\PayrollCalculator;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class ImportSynonymDictionaryTest extends TestCase
{
    private EmployeeImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmployeeImportService(new SpreadsheetReader(), new PayrollCalculator());
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($this->service, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this->service, ...$arguments);
    }

    public function test_tokens_normalise_arabic_and_keep_word_boundaries(): void
    {
        $this->assertSame(['الراتب', 'الاساسي'], $this->invoke('tokens', "  الرَّاتِب الأساسي "));
        $this->assertSame(['gross', 'salary'], $this->invoke('tokens', 'Gross-Salary'));
    }

    public function test_contextual_match_resolves_a_unique_longer_header(): void
    {
        // «رقم التأمين الاجتماعي» is a known insurance-number alias, so a longer
        // header containing all of its words resolves contextually.
        $field = $this->invoke('contextualMatch', $this->invoke('tokens', 'رقم التأمين الاجتماعي للموظف'));

        $this->assertSame('insurance_number', $field);
    }

    public function test_contextual_match_refuses_a_tie_between_fields(): void
    {
        // «الراتب الإجمالي الصافي» contains both «الراتب الإجمالي» (gross) and
        // «الراتب الصافي» (net): ambiguous, never guessed.
        $this->assertNull($this->invoke('contextualMatch', $this->invoke('tokens', 'الراتب الإجمالي الصافي')));
    }

    public function test_bare_salary_headers_get_no_suggestion(): void
    {
        // Amer's rule: «الراتب»/«المرتب» alone never auto-map to net or gross.
        $aliases = $this->invoke('aliasMap');

        $this->assertNull($this->invoke('suggestField', $this->service->key('الراتب'), $aliases, []));
        $this->assertNull($this->invoke('suggestField', $this->service->key('المرتب'), $aliases, []));
    }

    public function test_unsupported_headers_are_labelled_and_never_suggested(): void
    {
        $aliases = $this->invoke('aliasMap');

        $this->assertSame('رقم جواز السفر', $this->invoke('unsupportedFieldLabel', $this->service->key('رقم جواز السفر')));
        $this->assertSame('رقم جواز السفر', $this->invoke('unsupportedFieldLabel', $this->service->key('Passport Number')));
        $this->assertNull($this->invoke('suggestField', $this->service->key('رقم جواز السفر'), $aliases, []));
        $this->assertNull($this->invoke('suggestField', $this->service->key('Passport Number'), $aliases, []));
    }

    public function test_split_name_columns_compose_the_full_name(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'names') . '.xlsx';
        (new XlsxWriter())->writeSheets($path, [[
            'name' => 'الموظفين',
            'rows' => [
                ['الاسم الأول', 'اسم الأب', 'اسم العائلة', 'القسم'],
                ['أحمد', 'محمد', 'علي', 'الهندسة'],
            ],
        ]]);

        $analysis = $this->service->analyze($path, 'xlsx', 0);

        $this->assertSame('أحمد محمد علي', $analysis['rows'][0]['name'] ?? null);
        $this->assertSame('أحمد محمد علي', $analysis['rows'][0]['data']['full_name'] ?? null);
    }

    public function test_learning_is_a_no_op_before_the_migration_runs(): void
    {
        $schema = new class
        {
            public function hasTable(): bool
            {
                return false;
            }

            public function __call(string $method, array $args): mixed
            {
                return null;
            }
        };
        Schema::swap($schema);

        try {
            $learned = $this->service->learnMappings(7, ['رقم الملف' => 'employee_code'], ['columns' => []]);
        } finally {
            Schema::clearResolvedInstances();
        }

        $this->assertSame(0, $learned);
    }

    public function test_reviewed_corrections_hold(): void
    {
        $aliases = $this->invoke('aliasMap');

        // Approved review: last-working-day / resignation dates are NOT the same
        // as the end-of-service date, so they must never be suggested for it.
        $this->assertNull($this->invoke('suggestField', $this->service->key('آخر يوم عمل'), $aliases, []));
        $this->assertNotSame('termination_date', $this->invoke('suggestField', $this->service->key('تاريخ الاستقالة'), $aliases, []));

        // Approved review: "Start Date" inside an employees sheet is the hire date.
        $this->assertSame('hire_date', $this->invoke('suggestField', $this->service->key('Start Date'), $aliases, []));

        // Approved review: «التاريخ» alone is never an employees-sheet field.
        $this->assertNull($this->invoke('suggestField', $this->service->key('التاريخ'), $aliases, []));
    }
}
