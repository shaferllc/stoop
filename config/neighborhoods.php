<?php

/*
 * Ward and Advisory Neighborhood Commission for each DC Metro station, looked up from the
 * station's location in DC GIS (Ward - 2022 and ANC - 2023 boundary layers). Verify before
 * launch, and again after the next redistricting.
 */

return [

    // Station code => [ward, ANC].
    'stations' => [
        'A01' => [2, '2C'], // Metro Center
        'A02' => [2, '2C'], // Farragut North
        'A03' => [2, '2B'], // Dupont Circle
        'A04' => [3, '3C'], // Woodley Park
        'A05' => [3, '3C'], // Cleveland Park
        'A06' => [3, '3F'], // Van Ness-UDC
        'A07' => [3, '3E'], // Tenleytown
        'A08' => [3, '3E'], // Friendship Heights
        'B01' => [2, '2C'], // Gallery Place
        'B02' => [6, '6E'], // Judiciary Sq
        'B03' => [6, '6C'], // Union Station
        'B35' => [6, '6E'], // NoMa
        'B04' => [5, '5C'], // Rhode Island Ave
        'B05' => [5, '5B'], // Brookland
        'B06' => [5, '5A'], // Fort Totten
        'B07' => [4, '4B'], // Takoma
        'C02' => [2, '2C'], // McPherson Sq
        'C03' => [2, '2C'], // Farragut West
        'C04' => [2, '2A'], // Foggy Bottom
        'D01' => [2, '2C'], // Federal Triangle
        'D02' => [2, '2C'], // Smithsonian
        'D03' => [6, '6D'], // L'Enfant Plaza
        'D04' => [6, '6D'], // Federal Center SW
        'D05' => [6, '6B'], // Capitol South
        'D06' => [6, '6B'], // Eastern Market
        'D07' => [6, '6B'], // Potomac Ave
        'D08' => [7, '7D'], // Stadium-Armory
        'D09' => [7, '7F'], // Minnesota Ave
        'D10' => [7, '7C'], // Deanwood
        'E01' => [2, '2G'], // Mt Vernon Sq
        'E02' => [2, '2G'], // Shaw
        'E03' => [1, '1B'], // U Street
        'E04' => [1, '1A'], // Columbia Hts
        'E05' => [4, '4C'], // Petworth
        'F02' => [2, '2C'], // Archives
        'F04' => [6, '6D'], // Waterfront
        'F05' => [8, '8F'], // Navy Yard
        'F06' => [8, '8A'], // Anacostia
        'F07' => [8, '8C'], // Congress Heights
        'G01' => [7, '7F'], // Benning Rd
    ],

    // Each ANC's page (with its meetings) on oanc.dc.gov is /taxonomy/term/{id}.
    // 3/4G and 6/8F span two wards and are named that way there.
    'anc_pages' => [
        '1A' => 18, '1B' => 19, '1C' => 20, '1D' => 21, '1E' => 22,
        '2A' => 23, '2B' => 24, '2C' => 25, '2D' => 26, '2E' => 27, '2F' => 28, '2G' => 29,
        '3A' => 30, '3B' => 31, '3C' => 32, '3D' => 33, '3E' => 34, '3F' => 35, '3G' => 36, '4G' => 36,
        '4A' => 37, '4B' => 38, '4C' => 39, '4D' => 40, '4E' => 41,
        '5A' => 42, '5B' => 43, '5C' => 44, '5D' => 45, '5E' => 46, '5F' => 47,
        '6A' => 48, '6B' => 49, '6C' => 50, '6D' => 51, '6E' => 52, '6F' => 53, '8F' => 53,
        '7B' => 55, '7C' => 56, '7D' => 57, '7E' => 58, '7F' => 59,
        '8A' => 60, '8B' => 61, '8C' => 62, '8D' => 63, '8E' => 64,
    ],

    // DC's open 311 data: service requests from the last 90 days, from DC GIS (no key needed).
    'dc311' => env('STOOP_DC_311', true),
    'dc311_url' => 'https://maps2.dcgis.dc.gov/dcgis/rest/services/DCGIS_DATA/ServiceRequests/MapServer/13/query',

    'pepco_outage_map' => 'https://www.pepco.com/outages/experiencing-an-outage/outage-map',

];
