<?php

declare(strict_types=1);

namespace App\Map;

final readonly class GeocodeResult
{
    public function __construct(
        /** The place as OpenStreetMap names it */
        public string $placeName,
        /** false: only the town was found (or the street is hidden), so the marker is on the town */
        public bool $exact,
    ) {
    }
}
