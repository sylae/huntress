<?php
/**
 * Copyright (c) 2020 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

require_once "vendor/autoload.php";

/*
// uncomment me to get a quickie for cli testing uwu
$req = [
    'sheetID' => '1kXME7JHB5VVa5pMBLy1qxlBBef7dkcs2JlXf3z97xpQ', 'sheetRange' => 'Queue!A10:H', 'action' => 'pushRow',
    'data' => [
        "2020-02-20",
        "Test",
        "Character",
        "Pending",
        "Huntress",
        "",
        "",
        "https://syl.ae/",
    ],
];
echo base64_encode(json_encode($req));
die();
// */

$stuff = json_decode(base64_decode($argv[1]), true);

$gc = new Google_Client();
$gc->setAuthConfig("google.json");
$gc->setApplicationName("Huntress");
$gc->setScopes([Google_Service_Sheets::SPREADSHEETS]);
$client = new Google_Service_Sheets($gc);

if (array_key_exists("action", $stuff)) {
    // we're doing a thing! okay.
    switch ($stuff['action']) {
        case "pushRow":
            // JESUS FUCK KEIRA DO NOT TOUCH THIS CODE EVER AGAIN. FUCKING CHRIST.
            $values = [
                $stuff['data'],
            ];

            $body = new Google_Service_Sheets_ValueRange([
                'values' => $values,
            ]);
            $params = [
                'valueInputOption' => "USER_ENTERED",
            ];
            $result = $client->spreadsheets_values->append($stuff['sheetID'], $stuff['sheetRange'], $body, $params);
            break;
    }
}

echo json_encode($client->spreadsheets_values->get($stuff['sheetID'], $stuff['sheetRange']), JSON_PRETTY_PRINT);
