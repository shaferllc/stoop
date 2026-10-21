<?php

/*
 * DC teams on the Sports page. Schedules and scores come from ESPN's public, keyless JSON
 * (site.api.espn.com/apis/site/v2/sports/{espn}/schedule), standings from apis/v2/sports/{league path}/standings.
 * Keys are our slugs (/sports/{key}); Terps football and basketball share ESPN id 120.
 * "stop" is the home venue's nearest Metro station. "names" are what people type to @gameday.
 * Ticket links go to each team's own page. Stoop sells nothing.
 */
return [
    'teams' => [
        'commanders' => [
            'name' => 'Commanders', 'full' => 'Washington Commanders', 'league' => 'NFL', 'espn' => 'football/nfl/teams/wsh', 'espn_id' => '28',
            'colors' => ['#5a1414', '#ffb612'], 'venue' => 'Northwest Stadium', 'stop' => 'G05',
            'tickets' => 'https://www.commanders.com/tickets/', 'names' => ['commanders', 'commies', 'wash'],
        ],
        'nationals' => [
            'name' => 'Nationals', 'full' => 'Washington Nationals', 'league' => 'MLB', 'espn' => 'baseball/mlb/teams/wsh', 'espn_id' => '20',
            'colors' => ['#ab0003', '#11225b'], 'venue' => 'Nationals Park', 'stop' => 'F05',
            'tickets' => 'https://www.mlb.com/nationals/tickets', 'names' => ['nationals', 'nats'],
        ],
        'wizards' => [
            'name' => 'Wizards', 'full' => 'Washington Wizards', 'league' => 'NBA', 'espn' => 'basketball/nba/teams/wsh', 'espn_id' => '27',
            'colors' => ['#e31837', '#002b5c'], 'venue' => 'Capital One Arena', 'stop' => 'B01',
            'tickets' => 'https://www.nba.com/wizards/tickets', 'names' => ['wizards', 'wiz'],
        ],
        'capitals' => [
            'name' => 'Capitals', 'full' => 'Washington Capitals', 'league' => 'NHL', 'espn' => 'hockey/nhl/teams/wsh', 'espn_id' => '23',
            'colors' => ['#d71830', '#0b1f41'], 'venue' => 'Capital One Arena', 'stop' => 'B01',
            'tickets' => 'https://www.nhl.com/capitals/tickets', 'names' => ['capitals', 'caps'],
        ],
        'mystics' => [
            'name' => 'Mystics', 'full' => 'Washington Mystics', 'league' => 'WNBA', 'espn' => 'basketball/wnba/teams/wsh', 'espn_id' => '16',
            'colors' => ['#e03a3e', '#002b5c'], 'venue' => 'CareFirst Arena', 'stop' => 'F07',
            'tickets' => 'https://mystics.wnba.com/tickets', 'names' => ['mystics'],
        ],
        'dc-united' => [
            'name' => 'D.C. United', 'full' => 'D.C. United', 'league' => 'MLS', 'espn' => 'soccer/usa.1/teams/193', 'espn_id' => '193',
            'colors' => ['#000000', '#d61018'], 'venue' => 'Audi Field', 'stop' => 'F05',
            'tickets' => 'https://www.dcunited.com/tickets/', 'names' => ['united', 'dcu', 'dc united', 'd.c. united'],
        ],
        'spirit' => [
            'name' => 'Spirit', 'full' => 'Washington Spirit', 'league' => 'NWSL', 'espn' => 'soccer/usa.nwsl/teams/15365', 'espn_id' => '15365',
            'colors' => ['#000000', '#ede939'], 'venue' => 'Audi Field', 'stop' => 'F05',
            'tickets' => 'https://washingtonspirit.com/tickets/', 'names' => ['spirit'],
        ],
        'georgetown' => [
            'name' => 'Hoyas', 'full' => 'Georgetown men’s basketball', 'league' => 'NCAAM', 'espn' => 'basketball/mens-college-basketball/teams/46', 'espn_id' => '46',
            'colors' => ['#110e42', '#8d817b'], 'venue' => 'Capital One Arena', 'stop' => 'B01',
            'tickets' => 'https://guhoyas.com/tickets', 'names' => ['hoyas', 'georgetown', 'gtown'],
        ],
        'terps-football' => [
            'name' => 'Terps football', 'full' => 'Maryland football', 'league' => 'NCAAF', 'espn' => 'football/college-football/teams/120', 'espn_id' => '120',
            'colors' => ['#ce1126', '#ffd520'], 'venue' => 'SECU Stadium', 'stop' => 'E09',
            'tickets' => 'https://umterps.com/tickets', 'names' => ['terps football', 'maryland football'],
        ],
        'terps-basketball' => [
            'name' => 'Terps basketball', 'full' => 'Maryland men’s basketball', 'league' => 'NCAAM', 'espn' => 'basketball/mens-college-basketball/teams/120', 'espn_id' => '120',
            'colors' => ['#ce1126', '#ffd520'], 'venue' => 'Xfinity Center', 'stop' => 'E09',
            'tickets' => 'https://umterps.com/tickets', 'names' => ['terps', 'terps basketball', 'maryland'],
        ],
    ],
];
