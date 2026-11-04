<?php

namespace FahmiAzizHakim\LaravelAutoRollback\Tests\Unit;

use FahmiAzizHakim\LaravelAutoRollback\Mode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModeTest extends TestCase
{
    public static function values(): array
    {
        return [
            'ON' => ['ON', Mode::On],
            'on' => ['on', Mode::On],
            'true (bool)' => [true, Mode::On],
            'true (string)' => ['TRUE', Mode::On],
            '1' => ['1', Mode::On],
            'OFF' => ['OFF', Mode::Off],
            'false (bool)' => [false, Mode::Off],
            'null' => [null, Mode::Off],
            'empty' => ['', Mode::Off],
            'unknown' => ['maybe', Mode::Off],
            'ON_ERROR' => ['ON_ERROR', Mode::OnError],
            'on_error' => ['on_error', Mode::OnError],
            'on-error' => ['on-error', Mode::OnError],
            'padded' => [' on_error ', Mode::OnError],
        ];
    }

    #[DataProvider('values')]
    public function test_it_reads_the_env_value(mixed $value, Mode $expected): void
    {
        $this->assertSame($expected, Mode::fromConfig($value));
    }
}
