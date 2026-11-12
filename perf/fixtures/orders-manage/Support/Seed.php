<?php

namespace Livewire\Performance\Fixtures\Support;

/**
 * Deterministic synthetic data. Everything derives from crc32(salt:index), so output is
 * identical on every run and no global RNG state is touched. All values are fake.
 */
final class Seed
{
    public const LINES_FOR_FIXTURE_VENDOR = 240;

    public const OTHER_LINES = 60;

    public const FIXTURE_VENDOR_ID = 1;

    public static function h(string $salt, int $i, int $mod): int
    {
        return crc32($salt.':'.$i) % max($mod, 1);
    }

    public static function pick(string $salt, int $i, array $options): mixed
    {
        return $options[self::h($salt, $i, count($options))];
    }

    public static function date(string $salt, int $i, int $spreadDays = 60): string
    {
        return date('Y-m-d', strtotime('2030-01-01') + self::h($salt, $i, $spreadDays) * 86400);
    }

    public static function money(string $salt, int $i, int $maxCents = 90000): float
    {
        return round((self::h($salt, $i, $maxCents) + 100) / 100, 2);
    }

    public static function words(string $salt, int $i, int $count): string
    {
        static $vocab = [
            'Alpha', 'Bracket', 'Cuff', 'Delta', 'Elbow', 'Foam', 'Gasket', 'Hinge', 'Insert', 'Joint',
            'Knee', 'Liner', 'Mesh', 'Nylon', 'Orbit', 'Pad', 'Quartz', 'Rivet', 'Strap', 'Tube',
            'Unit', 'Valve', 'Wrap', 'Xenon', 'Yoke', 'Zip',
        ];

        $out = [];

        for ($k = 0; $k < $count; $k++) {
            $out[] = $vocab[self::h($salt.$k, $i, count($vocab))];
        }

        return implode(' ', $out);
    }
}
