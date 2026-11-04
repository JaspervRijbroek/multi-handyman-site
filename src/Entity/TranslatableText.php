<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Translatable texts are stored as JSON maps: ['en' => '...', 'es' => '...'].
 */
final class TranslatableText
{
    /**
     * @param array<string, string|null>|null $values
     *
     * @return array<string, string>
     */
    public static function clean(?array $values): array
    {
        $clean = [];
        foreach ($values ?? [] as $locale => $text) {
            $text = trim((string) $text);
            if ('' !== $text) {
                $clean[(string) $locale] = $text;
            }
        }

        return $clean;
    }
}
