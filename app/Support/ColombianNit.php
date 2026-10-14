<?php

namespace App\Support;

use InvalidArgumentException;

final class ColombianNit
{
    private const WEIGHTS = [71, 67, 59, 53, 47, 43, 41, 37, 29, 23, 19, 17, 13, 7, 3];

    public static function normalize(string $nit): string
    {
        return preg_replace('/\D+/', '', $nit) ?? '';
    }

    public static function verificationDigit(string $nit): string
    {
        $number = self::normalize($nit);
        if ($number === '' || strlen($number) > count(self::WEIGHTS)) {
            throw new InvalidArgumentException('El NIT debe contener entre 1 y 15 dígitos.');
        }

        $offset = count(self::WEIGHTS) - strlen($number);
        $sum = 0;
        foreach (str_split($number) as $index => $digit) {
            $sum += (int) $digit * self::WEIGHTS[$offset + $index];
        }

        $remainder = $sum % 11;

        return (string) ($remainder > 1 ? 11 - $remainder : $remainder);
    }

    public static function resolveVerificationDigit(string $nit, ?string $provided = null): string
    {
        $provided = trim((string) $provided);
        if ($provided !== '') {
            return $provided;
        }

        return self::verificationDigit($nit);
    }

    /** @return array{0: string, 1: string} */
    public static function split(string $value, bool $calculateMissingDigit = false): array
    {
        $value = trim($value);
        if (preg_match('/^(.+?)-(\d)$/', $value, $match) === 1) {
            return [self::normalize($match[1]), $match[2]];
        }

        $number = self::normalize($value);
        $digit = '';
        if ($calculateMissingDigit && $number !== '' && strlen($number) <= count(self::WEIGHTS)) {
            $digit = self::verificationDigit($number);
        }

        return [$number, $digit];
    }
}
