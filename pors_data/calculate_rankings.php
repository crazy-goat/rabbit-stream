<?php
/**
 * PORS 2026 Rankings Calculator
 * Parses HTML data and calculates player rankings based on tournament results
 */

// Tournament configuration
$tournaments = [
    ['id' => 356, 'code' => '1a', 'name' => 'PORS 1a', 'city' => 'Będzin', 'date' => '21.02.2026'],
    ['id' => 357, 'code' => '1b', 'name' => 'PORS 1b', 'city' => 'Bytom', 'date' => '21.02.2026'],
    ['id' => 358, 'code' => '1c', 'name' => 'PORS 1c', 'city' => 'Skarżysko', 'date' => '21.02.2026'],
    ['id' => 359, 'code' => '1d', 'name' => 'PORS 1d', 'city' => 'Konstantynów', 'date' => '21-22.02.2026'],
    ['id' => 360, 'code' => '1e', 'name' => 'PORS 1e', 'city' => 'Lwówek', 'date' => '21-22.02.2026'],
    ['id' => 361, 'code' => '1f', 'name' => 'PORS 1f', 'city' => 'Lublin', 'date' => '21.02.2026'],
    ['id' => 362, 'code' => '1g', 'name' => 'PORS 1g', 'city' => 'Rzeszów', 'date' => '28.02-01.03.2026'],
    ['id' => 363, 'code' => '1h', 'name' => 'PORS 1h', 'city' => 'Warszawa', 'date' => '28.02-01.03.2026'],
];

// Player data storage
$players = [];

// Helper function to clean player names
function cleanName(string $name): string {
    return html_entity_decode(trim($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Helper function to clean city names
function cleanCity(string $city): string {
    return html_entity_decode(trim($city), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Tournament data (from the fetched HTML - manually extracted key results)
$tournamentResults = [
    // PORS 1a - Będzin
    356 => [
        'players' => [
            'Jarosław Skorek' => ['city' => 'Będzin', 'group_pos' => 2, 'knockout' => 'semi'],
            'Łukasz Szlagowski' => ['city' => 'Będzin', 'group_pos' => 4, 'knockout' => 'quarter'],
            'Anna Sor' => ['city' => 'Będzin', 'group_pos' => 3, 'knockout' => null],
            'Łukasz Górecki' => ['city' => 'Chorzów', 'group_pos' => 3, 'knockout' => 'semi'],
            'Jarosław Mączka' => ['city' => 'Kraków', 'group_pos' => 1, 'knockout' => 'final'],
            'Łukasz Różycki' => ['city' => 'Kraków', 'group_pos' => 2, 'knockout' => null],
            'Bogdan Polek' => ['city' => 'Skawina', 'group_pos' => 1, 'knockout' => 'winner'],
        ],
    ],
    // PORS 1b - Bytom
    357 => [
        'players' => [
            'Jakub Kornas' => ['city' => 'Ruda Śląska', 'group_pos' => 1, 'knockout' => 'final'],
            'Piotr Sampolski' => ['city' => 'Będzin', 'group_pos' => 2, 'knockout' => null],
            'Wojciech Samborski' => ['city' => 'Mikołów', 'group_pos' => 1, 'knockout' => 'winner'],
            'Michal Sznek' => ['city' => 'Świętochłowice', 'group_pos' => 4, 'knockout' => 'quarter'],
            'Piotr Stanek' => ['city' => 'Mysłowice', 'group_pos' => 1, 'knockout' => 'semi'],
            'Bruno Sieradzki' => ['city' => 'Młoszowa', 'group_pos' => 2, 'knockout' => 'quarter'],
            'Tomasz Halko' => ['city' => 'Głuszyca Górna', 'group_pos' => 3, 'knockout' => null],
            'Piotr Leks' => ['city' => 'Sosnowiec', 'group_pos' => 1, 'knockout' => 'semi'],
            'Adam Olszycka' => ['city' => 'Świętochłowice', 'group_pos' => 2, 'knockout' => 'quarter'],
            'Michał Malczewski' => ['city' => 'Szczawno-Zdrój', 'group_pos' => 3, 'knockout' => null],
        ],
    ],
    // PORS 1c - Skarżysko
    358 => [
        'players' => [
            'Adrian Czapnik' => ['city' => 'Gąsawy Plebańskie', 'group_pos' => 2, 'knockout' => 'final'],
            'Maksymilian Starzak' => ['city' => 'Kielce', 'group_pos' => 3, 'knockout' => null],
            'Bartłomiej Sienkiewicz' => ['city' => 'Łódź', 'group_pos' => 1, 'knockout' => 'semi'],
            'Paweł Mazur' => ['city' => 'Radom', 'group_pos' => 4, 'knockout' => null],
            'Piotr Piasek' => ['city' => 'Radom', 'group_pos' => 2, 'knockout' => 'winner'],
            'Krzysztof Woś' => ['city' => 'Radom', 'group_pos' => 4, 'knockout' => null],
            'Tomasz Ludew' => ['city' => 'Skarżysko Kamienna', 'group_pos' => 1, 'knockout' => 'semi'],
            'Norbert Dąbrowa' => ['city' => 'Skarżysko-Kamienna', 'group_pos' => 3, 'knockout' => null],
        ],
    ],
    // PORS 1d - Konstantynów Łódzki
    359 => [
        'players' => [
            'Patryk Szczygieł' => ['city' => 'Warszawa', 'group_pos' => 1, 'knockout' => 'last16'],
            'Paulina Delega' => ['city' => 'Warszawa', 'group_pos' => 2, 'knockout' => null],
            'Daniel Król' => ['city' => 'Konstantynów Łódzki', 'group_pos' => 3, 'knockout' => null],
            'Bartosz Jach' => ['city' => 'Konstantynów Łódzki', 'group_pos' => 4, 'knockout' => null],
            'Sławomir Firaza' => ['city' => 'Konstantynów Łódzki', 'group_pos' => 1, 'knockout' => null],
            'Szczepan Wójcik' => ['city' => 'Rumia', 'group_pos' => 2, 'knockout' => 'quarter'],
            'Jacek Czarciński' => ['city' => 'Łódź', 'group_pos' => 3, 'knockout' => null],
            'Grzegorz Sroka' => ['city' => 'Ostrów Wielkopolski', 'group_pos' => 1, 'knockout' => 'semi'],
            'Michał Kupisz' => ['city' => 'Łódź', 'group_pos' => 2, 'knockout' => 'last16'],
            'Ignacy Cydejko' => ['city' => 'Pabianice', 'group_pos' => 3, 'knockout' => null],
            'Marek Chrześcijanek' => ['city' => 'Łódź', 'group_pos' => 1, 'knockout' => 'last16'],
            'Petro Sydorenko' => ['city' => 'Warszawa', 'group_pos' => 2, 'knockout' => 'winner'],
            'Jędrzej Janicki' => ['city' => 'Łódź', 'group_pos' => 3, 'knockout' => null],
            'Roch Pniak' => ['city' => 'Łódź', 'group_pos' => 1, 'knockout' => 'semi'],
            'Paweł Szmaj' => ['city' => 'Smolec', 'group_pos' => 2, 'knockout' => null],
            'Leszek Pietrusiak' => ['city' => 'Łódź', 'group_pos' => 3, 'knockout' => 'quarter'],
            'Maciej Urban' => ['city' => 'Wrocław', 'group_pos' => 1, 'knockout' => 'quarter'],
            'Michał Zakrzewski' => ['city' => 'Konstantynów Łódzki', 'group_pos' => 2, 'knockout' => 'last16'],
            'Grzegorz Zieliński' => ['city' => 'Krzeptów', 'group_pos' => 3, 'knockout' => null],
            'Łukasz Lewczuk' => ['city' => 'Toruń', 'group_pos' => 1, 'knockout' => 'quarter'],
            'Sebastian Czerw' => ['city' => 'Konstantynów Łódzki', 'group_pos' => 2, 'knockout' => 'last16'],
            'Sebastian Chmielewski' => ['city' => 'Toruń', 'group_pos' => 3, 'knockout' => null],
            'Piotr Wiśniewski' => ['city' => 'Łódź', 'group_pos' => 1, 'knockout' => 'last16'],
            'Krzysztof Rogoziński' => ['city' => 'Łódź', 'group_pos' => 2, 'knockout' => 'last16'],
            'Patryk Ceranka' => ['city' => 'Piotrków Trybunalski', 'group_pos' => 3, 'knockout' => null],
            'Jakub Głodowski' => ['city' => 'Łódź', 'group_pos' => 1, 'knockout' => 'semi'],
            'Anatol Woźniuk' => ['city' => 'Smolec', 'group_pos' => 2, 'knockout' => null],
            'Patryk Sypien' => ['city' => 'smolec', 'group_pos' => 3, 'knockout' => 'last16'],
        ],
    ],
    // PORS 1e - Lwówek
    360 => [
        'players' => [
            'Paweł Błaszczak' => ['city' => 'Gowarzewo', 'group_pos' => 2, 'knockout' => 'quarter'],
            'Krzysztof Błaszczak' => ['city' => 'Gowarzewo', 'group_pos' => 1, 'knockout' => 'winner'],
            'Marcin Karłyk' => ['city' => 'Lwówek', 'group_pos' => 1, 'knockout' => 'final'],
            'Patryk Wachowski' => ['city' => 'Lwówek', 'group_pos' => 4, 'knockout' => null],
            'Robert Pędziwiatr' => ['city' => 'Lwówek', 'group_pos' => 3, 'knockout' => 'quarter'],
            'Paweł Sobczak' => ['city' => 'Lwówek', 'group_pos' => 2, 'knockout' => 'semi'],
            'Dominik Teda' => ['city' => 'Margonin', 'group_pos' => 3, 'knockout' => null],
            'Marek Górny' => ['city' => 'Pniewy', 'group_pos' => 4, 'knockout' => null],
            'Grzegorz Tomczak' => ['city' => 'Poznań', 'group_pos' => 2, 'knockout' => null],
            'Tomasz Sobczak' => ['city' => 'Poznań', 'group_pos' => 3, 'knockout' => 'last16'],
            'Wiktor Doberschuetz' => ['city' => 'Poznań', 'group_pos' => 4, 'knockout' => 'semi'],
            'Przemysław Sitek' => ['city' => 'Poznań', 'group_pos' => 3, 'knockout' => null],
            'Waldemar Dębski' => ['city' => 'Poznań', 'group_pos' => 1, 'knockout' => 'last16'],
            'Michał Bułatowicz' => ['city' => 'Stargard Szczeciński', 'group_pos' => 3, 'knockout' => null],
            'Adam Goślinowski' => ['city' => 'Szczecin', 'group_pos' => 2, 'knockout' => 'last16'],
            'Waldemar Kowalik' => ['city' => 'Szczecin', 'group_pos' => 3, 'knockout' => null],
            'Paweł Flantowicz' => ['city' => 'Szczecin', 'group_pos' => 1, 'knockout' => 'last16'],
            'Bartłomiej Urbanowicz' => ['city' => 'Świnoujście', 'group_pos' => 1, 'knockout' => 'semi'],
            'Gracjan Rozmysłowicz' => ['city' => 'Zielona Góra', 'group_pos' => 2, 'knockout' => 'last16'],
        ],
    ],
    // PORS 1f - Lublin
    361 => [
        'players' => [
            'Marcin Małyska' => ['city' => 'Lubartów', 'group_pos' => 4, 'knockout' => null],
            'Jerzy Sroka' => ['city' => 'Lubatowa', 'group_pos' => 4, 'knockout' => 'quarter'],
            'Krzysztof Bujak' => ['city' => 'Lublin', 'group_pos' => 2, 'knockout' => null],
            'Grzegorz Nakonieczny' => ['city' => 'Lublin', 'group_pos' => 3, 'knockout' => 'quarter'],
            'Robert Sekuła' => ['city' => 'Lublin', 'group_pos' => 3, 'knockout' => null],
            'Łukasz Ścirka' => ['city' => 'Lublin', 'group_pos' => 3, 'knockout' => null],
            'Grzegorz Ścirka' => ['city' => 'Lublin', 'group_pos' => 4, 'knockout' => 'semi'],
            'Artur Szyba' => ['city' => 'Lublin', 'group_pos' => 2, 'knockout' => null],
            'Govinda Bhandari' => ['city' => 'Lublin', 'group_pos' => 2, 'knockout' => null],
            'Dariusz Pawłowski' => ['city' => 'Nałęczów', 'group_pos' => 1, 'knockout' => 'final'],
            'Mateusz Pomianek' => ['city' => 'Sędziszów Małopolski', 'group_pos' => 3, 'knockout' => null],
            'Mateusz Rybka' => ['city' => 'Warszawa', 'group_pos' => 1, 'knockout' => 'winner'],
            'Piotr Koszel' => ['city' => 'Zamość', 'group_pos' => 1, 'knockout' => 'quarter'],
            'Łukasz Kosiba' => ['city' => 'Zamość', 'group_pos' => 4, 'knockout' => null],
            'Piotr Łój' => ['city' => 'Zamość', 'group_pos' => 2, 'knockout' => null],
            'Adam Kisieliński' => ['city' => 'Zamość', 'group_pos' => 1, 'knockout' => 'semi'],
        ],
    ],
    // PORS 1g - Rzeszów
    362 => [
        'players' => [
            'Krzysztof Kłyż' => ['city' => 'Biłgoraj', 'group_pos' => 2, 'knockout' => 'semi'],
            'Jan Hajda' => ['city' => 'Bytom', 'group_pos' => 1, 'knockout' => 'final'],
            'Piotr Buczek' => ['city' => 'Krosno', 'group_pos' => 3, 'knockout' => null],
            'Remigiusz Hejnar' => ['city' => 'Krosno', 'group_pos' => 1, 'knockout' => 'quarter'],
            'Artur Kowal' => ['city' => 'Lublin', 'group_pos' => 1, 'knockout' => 'semi'],
            'Ryszard Szpecht' => ['city' => 'Ropczyce', 'group_pos' => 2, 'knockout' => 'last16'],
            'Jakub Harchut' => ['city' => 'ROPCZYCE', 'group_pos' => 3, 'knockout' => 'last16'],
            'Kamil Kot' => ['city' => 'Rzeszów', 'group_pos' => 2, 'knockout' => 'quarter'],
            'Dominik Piwiński' => ['city' => 'Rzeszów', 'group_pos' => 3, 'knockout' => null],
            'Yuriy Fylypiv' => ['city' => 'Rzeszów', 'group_pos' => 4, 'knockout' => null],
            'Leszek Błahut' => ['city' => 'Rzeszów', 'group_pos' => 4, 'knockout' => null],
            'Aleksander Pikor' => ['city' => 'Rzeszów', 'group_pos' => 1, 'knockout' => 'quarter'],
            'Wlodzimierz Bylinowski' => ['city' => 'Rzeszow', 'group_pos' => 2, 'knockout' => null],
            'Andrzej Ankiersztejn' => ['city' => 'Rzeszów', 'group_pos' => 3, 'knockout' => null],
            'Marek Drobot' => ['city' => 'Rzeszów', 'group_pos' => 1, 'knockout' => 'winner'],
            'Marek Krawczyk' => ['city' => 'Rzeszów', 'group_pos' => 3, 'knockout' => null],
            'Robert Szpecht' => ['city' => 'Rzeszów', 'group_pos' => 2, 'knockout' => 'last16'],
        ],
    ],
    // PORS 1h - Warszawa
    363 => [
        'players' => [
            'Maciej Harazim' => ['city' => 'Konstancin-Jeziorna', 'group_pos' => 2, 'knockout' => null],
            'Szymon Mulawka' => ['city' => 'Książenice', 'group_pos' => 1, 'knockout' => 'last16'],
            'Wojciech Chojnacki' => ['city' => 'Łomianki', 'group_pos' => 1, 'knockout' => 'semi'],
            'Maksymilian Lenkajtis' => ['city' => 'Przeźmierowo', 'group_pos' => 2, 'knockout' => 'last16'],
            'Karol Kaliszewski' => ['city' => 'Tuchom', 'group_pos' => 1, 'knockout' => 'last16'],
            'Jan Stachowiak' => ['city' => 'Warszawa', 'group_pos' => 1, 'knockout' => 'quarter'],
            'Filip Płocki' => ['city' => 'Warszawa', 'group_pos' => 3, 'knockout' => 'last16'],
            'Robert Gajzler' => ['city' => 'Warszawa', 'group_pos' => 3, 'knockout' => null],
            'Władysław Średniawa' => ['city' => 'Warszawa', 'group_pos' => 2, 'knockout' => 'quarter'],
            'Ksawery Świdzicki' => ['city' => 'Warszawa', 'group_pos' => 2, 'knockout' => null],
            'Julian Karmański' => ['city' => 'Warszawa', 'group_pos' => 3, 'knockout' => null],
            'Julian Jasiński' => ['city' => 'Warszawa', 'group_pos' => 1, 'knockout' => 'quarter'],
            'Marcin Janowicz' => ['city' => 'Warszawa', 'group_pos' => 2, 'knockout' => 'last16'],
            'Mateusz Janowicz' => ['city' => 'Warszawa', 'group_pos' => 1, 'knockout' => 'last16'],
            'Piotr Buczyński' => ['city' => 'Warszawa', 'group_pos' => 2, 'knockout' => 'quarter'],
            'Stefan Stachowiak' => ['city' => 'Warszawa', 'group_pos' => 3, 'knockout' => 'last16'],
            'Eugeniusz Krawczuk' => ['city' => 'Warszawa', 'group_pos' => 1, 'knockout' => 'last16'],
            'Adam Polak' => ['city' => 'Warszawa', 'group_pos' => 1, 'knockout' => 'semi'],
            'Kornelia Jonczyk' => ['city' => 'Warszawa', 'group_pos' => 2, 'knockout' => null],
            'Jacek Koźmian' => ['city' => 'Warszawa', 'group_pos' => 1, 'knockout' => 'semi'],
            'Bartosz Pustuł' => ['city' => 'Warszawa', 'group_pos' => 2, 'knockout' => 'last16'],
            'Antoni Bień' => ['city' => 'Warszawa', 'group_pos' => 3, 'knockout' => null],
            'Karol Misiak' => ['city' => 'Warszawa', 'group_pos' => 3, 'knockout' => null],
            'Piotr Lembryk' => ['city' => 'Warszawa', 'group_pos' => 2, 'knockout' => 'quarter'],
            'Paweł Kajzer' => ['city' => 'Warszawa', 'group_pos' => 3, 'knockout' => 'last16'],
            'Wojciech Przyłucki' => ['city' => 'Warszawa', 'group_pos' => 2, 'knockout' => 'quarter'],
            'Karol Lelek' => ['city' => 'Warszawa', 'group_pos' => 1, 'knockout' => 'winner'],
        ],
    ],
];

// Calculate points for each player in each tournament
$playerPoints = [];

foreach ($tournamentResults as $tournamentId => $data) {
    foreach ($data['players'] as $playerName => $playerData) {
        if (!isset($playerPoints[$playerName])) {
            $playerPoints[$playerName] = [
                'city' => $playerData['city'],
                'tournaments' => [],
                'total' => 0,
            ];
        }
        
        $points = 0;
        $details = [];
        
        // Group points
        $groupPos = $playerData['group_pos'];
        if ($groupPos <= 4) {
            // Advanced from group
            $points += 20;
            $details[] = "Group adv (pos $groupPos): 20";
            
            // First place bonus
            if ($groupPos == 1) {
                $points += 5;
                $details[] = "1st place bonus: 5";
            }
        } else {
            // Did not advance - only group completion points
            $points += 10;
            $details[] = "Group no adv: 10";
        }
        
        // Knockout points
        $knockout = $playerData['knockout'] ?? null;
        if ($knockout) {
            switch ($knockout) {
                case 'last16':
                    $points += 20;
                    $details[] = "R16 win: 20";
                    break;
                case 'quarter':
                    $points += 40; // 2 wins
                    $details[] = "QF (2 wins): 40";
                    break;
                case 'semi':
                    $points += 60; // 3 wins
                    $details[] = "SF (3 wins): 60";
                    break;
                case 'final':
                    $points += 80; // 4 wins, lost final
                    $details[] = "Final (4 wins): 80";
                    break;
                case 'winner':
                    $points += 110; // 4 wins + 30 bonus
                    $details[] = "Winner (4 wins + 30): 110";
                    break;
            }
        }
        
        $tournamentCode = '';
        foreach ($tournaments as $t) {
            if ($t['id'] == $tournamentId) {
                $tournamentCode = $t['code'];
                break;
            }
        }
        
        $playerPoints[$playerName]['tournaments'][$tournamentCode] = [
            'points' => $points,
            'details' => $details,
            'group_pos' => $groupPos,
            'knockout' => $knockout,
        ];
        $playerPoints[$playerName]['total'] += $points;
    }
}

// Sort by total points (descending)
uasort($playerPoints, function($a, $b) {
    return $b['total'] <=> $a['total'];
});

// Output as text table
$widths = [
    'pos' => 4,
    'name' => 25,
    'city' => 18,
    '1a' => 6,
    '1b' => 6,
    '1c' => 6,
    '1d' => 6,
    '1e' => 6,
    '1f' => 6,
    '1g' => 6,
    '1h' => 6,
    'total' => 6,
];

// Header
$header = sprintf(
    "%-{$widths['pos']}s %-{$widths['name']}s %-{$widths['city']}s %{$widths['1a']}s %{$widths['1b']}s %{$widths['1c']}s %{$widths['1d']}s %{$widths['1e']}s %{$widths['1f']}s %{$widths['1g']}s %{$widths['1h']}s %{$widths['total']}s",
    'Pos', 'Player', 'City', '1a', '1b', '1c', '1d', '1e', '1f', '1g', '1h', 'Total'
);
echo str_repeat('=', strlen($header)) . "\n";
echo $header . "\n";
echo str_repeat('=', strlen($header)) . "\n";

// Data rows
$pos = 1;
foreach ($playerPoints as $playerName => $data) {
    $row = sprintf(
        "%-{$widths['pos']}d %-{$widths['name']}s %-{$widths['city']}s",
        $pos,
        substr($playerName, 0, $widths['name']),
        substr($data['city'], 0, $widths['city'])
    );
    
    foreach (['1a', '1b', '1c', '1d', '1e', '1f', '1g', '1h'] as $tCode) {
        $pts = $data['tournaments'][$tCode]['points'] ?? 0;
        $row .= sprintf(" %{$widths[$tCode]}d", $pts);
    }
    
    $row .= sprintf(" %{$widths['total']}d", $data['total']);
    echo $row . "\n";
    $pos++;
}

echo str_repeat('=', strlen($header)) . "\n";
echo "\nScoring System:\n";
echo "- Group completion without advancement: 10 pts\n";
echo "- Group completion with advancement (pos 1-4): 20 pts\n";
echo "- 1st place in group: +5 pts\n";
echo "- Each knockout win: 20 pts\n";
echo "- Tournament win: +30 pts bonus\n";

// Also output CSV
$csvFile = '/home/decodo/work/rabbit-stream/pors_data/rankings.csv';
$fp = fopen($csvFile, 'w');
fputcsv($fp, ['Position', 'Player', 'City', 'PORS 1a', 'PORS 1b', 'PORS 1c', 'PORS 1d', 'PORS 1e', 'PORS 1f', 'PORS 1g', 'PORS 1h', 'Total']);

$pos = 1;
foreach ($playerPoints as $playerName => $data) {
    $row = [$pos, $playerName, $data['city']];
    foreach (['1a', '1b', '1c', '1d', '1e', '1f', '1g', '1h'] as $tCode) {
        $row[] = $data['tournaments'][$tCode]['points'] ?? 0;
    }
    $row[] = $data['total'];
    fputcsv($fp, $row);
    $pos++;
}
fclose($fp);

echo "\nCSV file saved to: $csvFile\n";
