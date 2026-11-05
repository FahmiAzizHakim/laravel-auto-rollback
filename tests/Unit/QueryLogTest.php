<?php

namespace FahmiAzizHakim\LaravelAutoRollback\Tests\Unit;

use FahmiAzizHakim\LaravelAutoRollback\QueryLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QueryLogTest extends TestCase
{
    public static function values(): array
    {
        return [
            'ROLLBACK' => ['ROLLBACK', QueryLog::Rollback],
            'rollback' => ['rollback', QueryLog::Rollback],
            'ALWAYS' => ['ALWAYS', QueryLog::Always],
            'always' => ['always', QueryLog::Always],
            'NEVER' => ['NEVER', QueryLog::Never],
            'never' => ['never', QueryLog::Never],
            'padded' => [' Always ', QueryLog::Always],
            'ALWAY' => ['ALWAY', QueryLog::Always],
            'true (bool)' => [true, QueryLog::Rollback],
            'false (bool)' => [false, QueryLog::Never],
            'null' => [null, QueryLog::Rollback],
            'empty' => ['', QueryLog::Rollback],
            'unknown' => ['sometimes', QueryLog::Rollback],
        ];
    }

    #[DataProvider('values')]
    public function test_it_reads_the_env_value(mixed $value, QueryLog $expected): void
    {
        $this->assertSame($expected, QueryLog::fromConfig($value));
    }
}
