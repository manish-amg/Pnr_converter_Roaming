<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Parser;

final class Passenger
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $type = null,
    ) {
    }
}
