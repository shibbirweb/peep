<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Sms;

/**
 * How many SMS parts a text needs. GSM-7 fits 160 characters (153 per part when split);
 * one character outside it makes the whole text UCS-2: 70 (67 per part).
 */
final class SmsSegments
{
    private const string GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    // These take two GSM-7 characters each (escape plus the character).
    private const string GSM_EXTENDED = "^{}\\[~]|€\f";

    private function __construct() {}

    /**
     * @return array{encoding: string, length: int, segments: int, per_segment: int}
     */
    public static function of(string $text): array
    {
        $characters = mb_str_split($text, 1, 'UTF-8');
        $units = 0;

        foreach ($characters as $character) {
            if (str_contains(self::GSM_EXTENDED, $character)) {
                $units += 2;
            } elseif (str_contains(self::GSM_BASIC, $character)) {
                $units++;
            } else {
                // UCS-2 counts UTF-16 code units: characters outside the BMP (emoji) take two.
                $ucs2 = 0;
                foreach ($characters as $each) {
                    $ucs2 += strlen((string) mb_convert_encoding($each, 'UTF-16BE', 'UTF-8')) / 2;
                }

                return self::result('UCS-2', (int) $ucs2, 70, 67);
            }
        }

        return self::result('GSM-7', $units, 160, 153);
    }

    /**
     * @return array{encoding: string, length: int, segments: int, per_segment: int}
     */
    private static function result(string $encoding, int $length, int $single, int $multi): array
    {
        $segments = $length <= $single ? 1 : (int) ceil($length / $multi);

        return [
            'encoding' => $encoding,
            'length' => $length,
            'segments' => $segments,
            'per_segment' => $segments === 1 ? $single : $multi,
        ];
    }
}
