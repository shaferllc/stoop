<?php

/*
 * Outbound reservation and ticket links. A provider's partner parameter is added to its
 * links only when its env value is set; only those links get rel="sponsored" and the
 * "Stoop may earn a commission" note. VERIFY each parameter name against the signed
 * partner agreement before setting its env value.
 */
return [

    'providers' => [
        'opentable' => ['name' => 'OpenTable', 'hosts' => ['opentable.com'], 'param' => 'ref', 'value' => env('OPENTABLE_PARTNER_ID')],
        'resy' => ['name' => 'Resy', 'hosts' => ['resy.com'], 'param' => 'aff_id', 'value' => env('RESY_AFFILIATE_ID')],
        'tock' => ['name' => 'Tock', 'hosts' => ['exploretock.com'], 'param' => 'ref', 'value' => env('TOCK_AFFILIATE_ID')],
        'eventbrite' => ['name' => 'Eventbrite', 'hosts' => ['eventbrite.com'], 'param' => 'aff', 'value' => env('EVENTBRITE_AFFILIATE_ID')],
        // Anything else: a restaurant's own site, or a host's own ticket page. Never an affiliate.
        'website' => ['name' => 'Own website', 'hosts' => [], 'param' => null, 'value' => null],
        'tickets' => ['name' => 'Other ticket sites', 'hosts' => [], 'param' => null, 'value' => null],
    ],

];
