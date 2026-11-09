<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The trades a client can be found for in the platform search.
 * Clients pick theirs in Settings; the value is stored in Tenant::$trades.
 */
enum Trade: string
{
    case Handyman = 'handyman';
    case Plumbing = 'plumbing';
    case Electrical = 'electrical';
    case Painting = 'painting';
    case Carpentry = 'carpentry';
    case Tiling = 'tiling';
    case Renovation = 'renovation';
    case Roofing = 'roofing';
    case AirConditioning = 'air-conditioning';
    case Garden = 'garden';
    case Pool = 'pool';
    case Locksmith = 'locksmith';

    public function label(): string
    {
        return match ($this) {
            self::Handyman => 'Handyman',
            self::Plumbing => 'Plumbing',
            self::Electrical => 'Electrical',
            self::Painting => 'Painting',
            self::Carpentry => 'Carpentry',
            self::Tiling => 'Tiling',
            self::Renovation => 'Building & renovation',
            self::Roofing => 'Roofing',
            self::AirConditioning => 'Air conditioning',
            self::Garden => 'Garden',
            self::Pool => 'Pool care',
            self::Locksmith => 'Locksmith',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $trade) => $trade->value, self::cases());
    }

    /** @return array<string, string> label => value, for choice fields */
    public static function choices(): array
    {
        return array_combine(
            array_map(static fn (self $trade) => $trade->label(), self::cases()),
            self::values(),
        );
    }
}
