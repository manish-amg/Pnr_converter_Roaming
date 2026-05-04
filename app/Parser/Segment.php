<?php
declare(strict_types=1);

namespace RoamingNepal\PnrConverter\Parser;

final class Segment
{
    public function __construct(
        public readonly string $airlineCode,
        public readonly string $flightNumber,
        public readonly ?string $airlineName,
        public readonly ?string $status,
        public readonly string $departureAirport,
        public readonly string $arrivalAirport,
        public readonly string $departureDate,
        public readonly string $departureTime,
        public readonly ?string $arrivalDate,
        public readonly string $arrivalTime,
        public readonly ?string $bookingClass = null,
        public readonly ?string $cabin = null,
        public readonly ?string $layoverDuration = null,
        public readonly ?string $operatedBy = null,
        public readonly ?string $ticketNumber = null,
        public readonly ?string $seatNumber = null,
        public readonly ?string $aircraft = null,
        public readonly string $rawLine = '',
    ) {
    }
}
