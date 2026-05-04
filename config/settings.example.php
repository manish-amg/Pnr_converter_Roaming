<?php
declare(strict_types=1);

return [
    'app_version' => '3.0.0',
    'agency_name' => 'Roaming Nepal',
    'logo_path' => 'assets/images/roaming-nepal-logo.png',
    'contact_phone' => '+(977) 015905391, 015905392',
    'contact_email' => '',
    'whatsapp' => '+(977) 9851075316, 9841093086, 9851193482',
    'footer_note' => 'Thank you for choosing Roaming Nepal.',
    'default_disclaimer' => 'Please verify final schedule, terminal and check-in details directly with the airline or airport before travel.',
    'base_url' => 'https://pnrconverter.roamingnepal.com',
    'privacy_logging_enabled' => false,
    'footer' => [
        'head_office' => [
            'title' => 'ROAMING NEPAL TRAVEL & TOURS PVT. LTD.',
            'lines' => [
                'A: Gairidhara-02, Nil Saraswoti Marg, Kathmandu, Nepal',
                'P: +(977) 015905391, 015905392',
                'M: +(977) 9851075316, 9841093086, 9851193482',
                'W: www.roamingnepal.com',
            ],
        ],
        'branches_title' => 'YOU CAN ALSO FIND US AT:',
        'branches' => [
            [
                'title' => 'POKHARA',
                'lines' => [
                    '#Pokhara-06, Lakeside (Khahare), Kaski, Nepal',
                    '+977-61-591401, 591402',
                ],
            ],
            [
                'title' => 'AUSTRALIA',
                'lines' => [
                    '#15 Crossing Road, Mernda, VIC, 3754, Australia',
                    '+(61) 0452055393',
                ],
            ],
        ],
    ],
    'features' => [
        'show_airline_name' => true,
        'show_airline_logo' => true,
        'show_agency_header' => true,
        'show_agency_footer' => true,
        'show_disclaimer' => true,
        'show_transit_time' => true,
        'distance_unit' => 'off',
        'use_12_hour_clock' => true,
        'show_operated_by' => false,
        'show_aircraft' => false,
        'result_format' => 'detailed',
        'show_booking_class' => true,
        'show_cabin' => true,
        'show_layover' => true,
        'use_24_hour_time' => false,
        'show_booking_reference' => true,
        'show_ticket_numbers' => false,
        'show_seat_numbers' => false,
        'mask_ticket_numbers' => true,
        'mask_seat_numbers' => false,
    ],
];
