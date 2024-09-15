<?php
$matchplay_ids = [];


function matchplayRequest($url) {
    $opts = [
    'http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer 315|2oAwBJlvCsGUTFrKc5FJI1IMH0ndfqqMASYQvZEB8b7c5d1a\r\n" .
                    "Content-Type: application/json\r\n" .
                    "Accept: application/json\r\n"
        ],
    ];

    // Create the stream context
    $context = stream_context_create($opts);

    // Fetch the URL content
    $file = file_get_contents($url, false, $context);

    if ($file === false) {
        echo "Error fetching data from URL: $url\n";
        return null;
    }

    return json_decode($file, true);
}

function getGamesInTournament($tournament_id) {

return matchplayRequest("https://app.matchplay.events/api/tournaments/{$tournament_id}/games");
}

function getGamesList($tournament_ids){

return matchplayRequest("https://app.matchplay.events/api/games?page=tournaments=$tournament_ids");
}

function fetchGamesAndArenas($tournament_ids) {
    
    $page = 1;
    $allGames = [];
    $hasMorePages = true;

    while ($hasMorePages) {
        $url = "https://app.matchplay.events/api/games?page=$page&tournaments=$tournament_ids";
        $response = matchplayRequest($url);

        // Check if response is valid
        if (isset($response['data']) && is_array($response['data'])) {
            foreach ($response['data'] as $game) {
                $allGames[] = [
                    'game_id' => $game['gameId'],
                    'arena_id' => $game['arenaId'],
                ];
            }

            // If the number of results is less than a full page, we've reached the last page
            if (count($response['data']) < 100) { // Assuming 100 is the default per-page limit
                $hasMorePages = false;
            } else {
                $page++;
            }
        } else {
            $hasMorePages = false; // Stop the loop if an invalid response is received
        }
    }

    return $allGames;
}

function getArenas($arena_ids) {
    $page = 1;
    $allArenas = [];
    $hasMorePages = true;

    $url = "https://app.matchplay.events/api/arenas?page=$page";
    $response = matchplayRequest($url);
    // while ($hasMorePages) {
    //     $url = "https://app.matchplay.events/api/arenas?page=$page&arenas=$arena_ids";
    //     $response = matchplayRequest($url);

    

    //     // Check if response is valid
    //     if (isset($response['data']) && is_array($response['data'])) {
    //         foreach ($response['data'] as $game) {
    //             $allArenas[] = [
    //                 'game_id' => $game['gameId'],
    //                 'arena_id' => $game['arenaId'],
    //             ];
    //         }

    //         // If the number of results is less than a full page, we've reached the last page
    //         if (count($response['data']) < 100) { // Assuming 100 is the default per-page limit
    //             $hasMorePages = false;
    //         } else {
    //             $page++;
    //         }
    //     } else {
    //         $hasMorePages = false; // Stop the loop if an invalid response is received
    //     }
    // }
    return $response;

    // return $allArenas;
}

// function getArenas

function playersTournaments($params = []) {
$baseUrl = 'https://app.matchplay.events/api/tournaments';
    $defaultParams = [
        'status' => 'completed',
        'owner' => '33652'
    ];

    // Merge default parameters with any additional parameters provided
    $queryParams = http_build_query(array_merge($defaultParams, $params));
    $url = "{$baseUrl}?{$queryParams}";

    // Make the API request
    $data = matchplayRequest($url);

    return $data;
}

function fetchAndStoreGameArena() {
    $tournaments = [
        47156, 47840, 
        131207, 140558, 140691, 30752, 134451, 143249

    ];

    $batch_size = 25;
    $tournament_batches = array_chunk($tournaments, $batch_size);
    $tournament_batch_count = count($tournament_batches);
    $games = [];
    $arenas = [];

    echo "Beginning API retrieval for $tournament_batch_count batches \n";
    $i = 1;

    foreach ($tournament_batches as $tournament_batch) {
        echo "Retrieving game_data for batch $i of $tournament_batch_count \n";
        
        // Fetch all games for the current batch of tournament_ids using fetchGamesAndArenas
        $games_data = fetchGamesAndArenas(implode(',', $tournament_batch));
        
        // Ensure the data is valid and an array
        if (!empty($games_data) && is_array($games_data)) {
            foreach ($games_data as $game) {
                // Store game and arena ids if arena_id is not empty
                if (!empty($game['arena_id'])) {
                    $games[] = [
                        'game_id' => $game['game_id'],
                        'arena_id' => $game['arena_id']
                    ];
                    echo "Stored game and arena ids in array for game_id: {$game['game_id']} arena_id: {$game['arena_id']} \n";
                    
                    // Store arena_id and tournament_id if arena_id is found
                    foreach ($tournament_batch as $tournament_id) {
                        $arenas[] = [
                            'arena_id' => $game['arena_id'],
                            'tournament_id' => $tournament_id
                        ];
                    }
                } else {
                    echo "Skipping game_id: {$game['game_id']} due to empty arena_id\n";
                }
            }
        } else {
            echo "No data found for batch $i.\n";
        }
        $i++;
    }

    // Chunk the array into smaller batches
    $batches = array_chunk($games, 10000); 
    $batch_count = count($batches);
    $conn = createConnection();
    echo "Starting to update the database in $batch_count batches.\n";

    foreach ($batches as $i => $batch) {
        $sql_update_games = "UPDATE games SET 
                arena_id = CASE game_id ";

        $ids = [];
        foreach ($batch as $game_data) {
            $escaped_arena_id = $conn->real_escape_string($game_data['arena_id']);
            $game_id = $game_data['game_id'];

            if (!empty($escaped_arena_id)) { 
                $sql_update_games .= "WHEN $game_id THEN '$escaped_arena_id' ";
                $ids[] = $game_id;
            } else {
                echo "Skipping empty arena_id for game_id: $game_id \n";
            }
        }

        if (!empty($ids)) {
            $sql_update_games .= "END 
                    WHERE game_id IN (" . implode(',', $ids) . ")";

            if ($conn->query($sql_update_games) === TRUE) {
                echo "Batch " . ($i + 1) . " updated successfully in games table.\n";
            } else {
                echo "Error updating records in games table: " . $conn->error . "\n";
                // Log the SQL error to a file for further analysis
                file_put_contents('sql_errors.log', "SQL Error: " . $conn->error . "\n", FILE_APPEND);
            }
        } else {
            echo "No valid game_ids to update in this batch.\n";
        }
    }

    echo "All games and arenas processed and stored successfully.\n";

    $conn->close();

    return $arenas;
}

function fetchAndStoreArenaNames() {
    $tournaments = [
        48036, 149340, 27281, 29462, 45364, 125161, 66512, 32584, 35905, 30325, 27428, 133011
    ];
    $arena_map = [];
    
    $arena_count = count($tournaments);
    $i = 1; // Initialize counter for progress display
    $delay_seconds = 30; // Set delay in seconds

    foreach ($tournaments as $tournament_id) {
        $tournament_data = getTournamentData($tournament_id); // Fetch tournament details

        if (isset($tournament_data['data'])) {
            // Loop through arenas in the tournament and update corresponding arenas in $arena_map
            if (isset($tournament_data['data']['arenas']) && is_array($tournament_data['data']['arenas'])) {
                foreach ($tournament_data['data']['arenas'] as $arena_data) {
                    // Store arena data in the map
                    $arena_map[] = [
                        'arena_id' => $arena_data['arenaId'],
                        'game_name' => $arena_data['name'],  // Ensure game_name is not null
                        'opdb_name' => null // Initialize as null; update if needed later
                    ];
                    
                    echo "UPDATE games SET game_name = '{$arena_data['name']}' WHERE arena_id = '{$arena_data['arenaId']}'; \n";

                }
            } else {
                echo "No arenas found for tournament_id: $tournament_id\n";
            }
        } else {
            echo "Warning: No data found for tournament_id: $tournament_id \n";
        }

        $i++;
        if ($i < $arena_count) {
            sleep($delay_seconds);
        }
    }

    // Batch process OPDB name retrieval, but only if a game_name was found
    $total_games = count($arena_map);
    $batch_size = 14; // Set a batch size appropriate for your case

    for ($i = 0; $i < $total_games; $i += $batch_size) {
        $batch = array_slice($arena_map, $i, $batch_size);
        $multiCurl = [];
        $mh = curl_multi_init();

        foreach ($batch as $key => $arena) {
            // Only request opdb_name if game_name is set
            if (!empty($arena['game_name'])) {
                $query = $arena['game_name'];
                $multiCurl[$key] = curl_init();

                $api_token = 'grjaSqwjXHro2561oNURv1LUmfTisROncFtLONvPtzG4Ujg3I1zhJj43gMYU';
                $data = [
                    'api_token' => $api_token,
                    'q' => $query,
                ];

                $query_string = http_build_query($data);
                $url = 'https://opdb.org/api/search?' . $query_string;

                curl_setopt($multiCurl[$key], CURLOPT_URL, $url);
                curl_setopt($multiCurl[$key], CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($multiCurl[$key], CURLOPT_HTTPGET, 1);
                curl_setopt($multiCurl[$key], CURLOPT_TIMEOUT, 30); // 30 seconds
                curl_setopt($multiCurl[$key], CURLOPT_CONNECTTIMEOUT, 30); // 30 seconds

                curl_multi_add_handle($mh, $multiCurl[$key]);
            }
        }

        // Execute the multi-cURL handles
        $index = null;
        do {
            curl_multi_exec($mh, $index);
        } while ($index > 0);

        // Retrieve the results for the current batch
        foreach ($multiCurl as $key => $ch) {
            $response = curl_multi_getcontent($ch);
            $response_data = json_decode($response, true);

            // Find and assign the "name" from the response
            if (isset($response_data[0]['name']) && !empty($response_data[0]['name'])) {
                $opdb_name = $response_data[0]['name'];
                $arena_map[$i + $key]['opdb_name'] = $opdb_name;

            } 

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        // Close the multi-cURL handle for the current batch
        curl_multi_close($mh);

        // Optional: Pause between batches to further reduce server load
        sleep(5); // Sleep for 1 second between batches
    }

    // At this point, $arena_map contains arena_id, game_name, organizer_id, and opdb_name for each arena.
    echo "OPDB search completed for all batches.\n";
    
    $conn = createConnection();
    // Update the games table with opdb_name
    $batch_size = 10000;
    $batches = array_chunk($arena_map, $batch_size); // Chunk the array into smaller batches
    $batch_count = count($batches);
    $i = 1;

    echo "Starting to update the database in $batch_count batches.\n";

    foreach ($batches as $batch) {
        $sql = "UPDATE games SET 
                    game_name = CASE arena_id ";

        $ids = [];
        foreach ($batch as $game_data) {
            $escaped_opdb_name = $conn->real_escape_string($game_data['opdb_name']);
            $sql .= "WHEN {$game_data['arena_id']} THEN '$escaped_opdb_name' ";
            $ids[] = $game_data['arena_id'];
        }

        $sql .= "END 
                WHERE arena_id IN (" . implode(',', $ids) . ")";

        if ($conn->query($sql) === TRUE) {
            echo "Batch $i of $batch_count updated successfully.\n";
            $i++;
        } else {
            echo "Error updating records: " . $conn->error . "\n";
        }
    }

    echo "All $total_games games processed and stored successfully.\n";

    $conn->close();
}





function getTournamentsList($params = []) {
// Array
// (
//     [data] => Array
//         (
//             [0] => Array
//                 (
//                     [tournamentId] => 103762
//                     [name] => NKY Pinball Open 2023
//                     [status] => completed
//                     [type] => group_matchplay
//                     [startUtc] => 2024-07-06T16:00:00.000000Z
//                     [startLocal] => 2024-07-06 12:00:00
//                     [endUtc] => 2024-07-06T16:00:00.000000Z
//                     [endLocal] => 2024-07-06 12:00:00
//                     [completedAt] => 2023-07-08T22:05:26.000000Z
//                     [organizerId] => 1030
//                     [locationId] => 1031
//                     [seriesId] => 
//                     [description] => $40 buy in (includes food, drinks, trophies).  $20 of every buy in goes directly to the prize pool.
// 10am practice. Noon start

// Matchplay qualifying and Finals

// Email Chuckwurt@msn.com for any questions.  Or text 859-816-2783
//                     [pointsMap] => Array
//                         (
//                             [0] => Array
//                                 (
//                                     [0] => 7
//                                 )

//                             [1] => Array
//                                 (
//                                     [0] => 7
//                                     [1] => 1
//                                 )

//                             [2] => Array
//                                 (
//                                     [0] => 7
//                                     [1] => 4
//                                     [2] => 1
//                                 )

//                             [3] => Array
//                                 (
//                                     [0] => 7
//                                     [1] => 5
//                                     [2] => 3
//                                     [3] => 1
//                                 )

//                         )
    $baseUrl = 'https://app.matchplay.events/api/tournaments';
    $defaultParams = [
        'status' => 'completed',
    ];

    // Merge default parameters with any additional parameters provided
    $queryParams = http_build_query(array_merge($defaultParams, $params));
    $url = "{$baseUrl}?{$queryParams}";

    // Make the API request
    $data = matchplayRequest($url);

    return $data;
}


function getAllTournaments($params = []) {
// Retrieves every completed tournament and stores it in the local db
    $baseUrl = 'https://app.matchplay.events/api/tournaments';
    $defaultParams = [
        'status' => 'completed',
    ];

    $page = 2554;
    $lastPage = 1;

    do {
        $queryParams = http_build_query(array_merge($defaultParams, $params, ['page' => $page]));
        $url = "{$baseUrl}?{$queryParams}";

        $data = matchplayRequest($url);

        if ($data === null) {
            echo "Error occurred during API request.\n";
            break;
        }

        if (isset($data['data']) && is_array($data['data'])) {
            storeTournamentsInDb($data['data']);
        } else {
            echo "Unexpected data format on page $page: ";
            print_r($data);
            break;
        }

        echo "Fetched page $page\n";

        if (isset($data['meta']['last_page'])) {
            $lastPage = $data['meta']['last_page'];
            echo "Meta info on page $page: last_page = $lastPage\n";
        } else {
            echo "Meta information not found on page $page, breaking the loop.\n";
            break;
        }

        $page++;
    } while ($page <= $lastPage);
}

function getGameData($tournament_id, $game_id) {
// Get player IDs and placements from game + tournament ID
// EX [gameId] => 4348941
//             [roundId] => 653403
//             [tournamentId] => 138243
//             [challengeId] => 
//             [arenaId] => 99219
//             [bankId] => 
//             [index] => 0
//             [set] => 6
//             [playerIdAdvantage] => 
//             [scorekeeperId] => 9076
//             [status] => completed
//             [startedAt] => 2024-05-17T03:04:03.000000Z
//             [duration] => 1354
//             [bye] => 
//             [playerIds] => Array
//                 (
//                     [0] => 238545
//                     [1] => 328349
//                     [2] => 238534
//                     [3] => 237878
//                 )

//             [userIds] => Array
//                 (
//                     [0] => 7363
//                     [1] => 23051
//                     [2] => 25844
//                     [3] => 8615
//                 )

//             [resultPositions] => Array
//                 (
//                     [0] => 328349
//                     [1] => 238545
//                     [2] => 237878
//                     [3] => 238534
//                 )

//             [resultPoints] => Array
//                 (
//                     [0] => 5.00
//                     [1] => 7.00
//                     [2] => 1.00
//                     [3] => 3.00
//                 )

//             [resultScores] => Array
//                 (
//                     [0] => 
//                     [1] => 
//                     [2] => 
//                     [3] => 
//                 )

//             [arena] => stdClass Object
//                 (
//                     [arenaId] => 99219
//                     [name] => Ghostbusters (Pro)
//                     [status] => active
//                     [opdbId] => GR9Nr-Mz2dY
//                     [categoryId] => 4
//                     [organizerId] => 2465
//                 )

//             [suggestions] => Array
//                 (
//                 )

//         )

    return matchplayRequest("https://app.matchplay.events/api/tournaments/$tournament_id/games/$game_id");

    // if ($data && isset($data['data'][0])) {
    //     $gameData = $data['data'][0];
    //     // Check if resultPositions exist
    //     if (isset($gameData['resultPositions']) && is_array($gameData['resultPositions'])) {
    //         // Map playerIds to resultPositions for storing placements
    //         $placements = $gameData['resultPositions'];
    //         // Store placements in the game table
    //         storePlacementsInDb($game_id, $placements);
    //         // Collect unique matchplay IDs
    //         if (isset($gameData['playerIds']) && is_array($gameData['playerIds'])) {
    //             collectMatchplayIds($gameData['playerIds']);
    //         }
    //     } else {
    //         echo "No resultPositions data found for game ID $game_id in tournament ID $tournament_id\n";
    //     }
    // } else {
    //     echo "No data found for game ID $game_id in tournament ID $tournament_id\n";
    // }
}


function getFilteredTournamentIds($ids) {
    $tournament_ids = array_column($ids, 'tournament_id');

    // Filter tournament_ids to include only those greater than 121385
    $filtered_tournament_ids = array_filter($tournament_ids, function($id) {
        return $id > 121385;
    });

    return $filtered_tournament_ids;
}

function fetchAndStoreOPDB() {
    $games = getAllGameIds(); // Retrieve game IDs and names
    $games_array = [];
    $total_games = count($games);
    $batch_size = 25; // Adjust this based on what the server can handle

    // Populate $games_array with game data
    foreach ($games as $game) {
        $game_name = $game['game_name']; // Assuming 'arena_name' is what you want to search for
        $games_array[] = [
            'game_name' => $game_name,
            'opdb_name' => null,
        ];
    }

    $results = [];
    for ($i = 0; $i < $total_games; $i += $batch_size) {
        $batch = array_slice($games_array, $i, $batch_size);
        $multiCurl = [];
        $mh = curl_multi_init();

        foreach ($batch as $key => $game) {
            $query = $game['game_name'];
            $multiCurl[$key] = curl_init();

            $api_token = 'grjaSqwjXHro2561oNURv1LUmfTisROncFtLONvPtzG4Ujg3I1zhJj43gMYU';
            $data = [
                'api_token' => $api_token,
                'q' => $query,
            ];

            $query_string = http_build_query($data);
            $url = 'https://opdb.org/api/search?' . $query_string;

            curl_setopt($multiCurl[$key], CURLOPT_URL, $url);
            curl_setopt($multiCurl[$key], CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($multiCurl[$key], CURLOPT_HTTPGET, 1);
            curl_setopt($multiCurl[$key], CURLOPT_TIMEOUT, 30);
            curl_setopt($multiCurl[$key], CURLOPT_CONNECTTIMEOUT, 30);

            curl_multi_add_handle($mh, $multiCurl[$key]);
        }

        // Execute the multi-cURL handles
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh);
            }
        } while ($active && $status == CURLM_OK);

        // Retrieve the results for the current batch
        foreach ($multiCurl as $key => $ch) {
            $response = curl_multi_getcontent($ch);
            $response_data = json_decode($response, true);

            // Find and assign the "name" from the response
            if (isset($response_data[0]['name']) && !empty($response_data[0]['name'])) {
                $opdb_name = $response_data[0]['name'];
                $games_array[$i + $key]['opdb_name'] = $opdb_name;
                echo "Processed game: {$batch[$key]['game_name']} - OPDB name found: $opdb_name.\n";
            } else {
                echo "No OPDB name found for game: {$batch[$key]['game_name']}.\n";
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        // Close the multi-cURL handle for the current batch
        curl_multi_close($mh);

        // Optional: Pause between batches to further reduce server load
        sleep(60);
    }

    // At this point, $games_array contains game_id, game_name, and opdb_name for each game.
    echo "OPDB search completed for all batches.\n";

    // Batch process the stored data
    $conn = createConnection();
    $i = 1;
    $batch_size = 1000;  // Use smaller batch size for better handling
    $batches = array_chunk($games_array, $batch_size);
    $batchSize = count($batches);

    echo "Starting to update the database in $batchSize batches.\n";

    foreach ($batches as $batch) {
        $sql = "UPDATE games SET opdb_name = CASE game_name ";

        $game_names = [];  // Prepare an array to collect game_names
        foreach ($batch as $game_data) {
            $escaped_opdb_name = $conn->real_escape_string($game_data['opdb_name']);
            $escaped_game_name = $conn->real_escape_string($game_data['game_name']);  // Escape game_name
            $sql .= "WHEN '$escaped_game_name' THEN '$escaped_opdb_name' ";
            $game_names[] = "'$escaped_game_name'";
        }

        $sql .= "END WHERE game_name IN (" . implode(',', $game_names) . ")";

        if ($conn->query($sql) === TRUE) {
            echo "Batch $i of $batchSize updated successfully.\n";
            $i++;
        } else {
            echo "Error updating records: " . $conn->error . "\n";
        }
    }

    echo "All $total_games games processed and stored successfully.\n";

    $conn->close();
}


function fetchAndStoreGameNames() {
    $games = getAllGameIds();
    $games_array = [];
    $total_games = count($games);
    $batch_size = 10; // Adjust this based on what the server can handle

    echo "Starting to process $total_games games\n";

    foreach ($games as $game) {
        $tournament_id = $game['tournament_id'];
        $game_id = $game['game_id'];

        $data = getGameData($tournament_id, $game_id);

        if (isset($data['data']['arena']) && is_array($data['data']['arena'])) {
            $arena_name = $data['data']['arena']['name'];
            $game_info = [
                'game_id' => $game_id,
                'game_name' => $arena_name,
                'opdb_name' => null, // Placeholder for the OPDB name
            ];

            $games_array[] = $game_info;
            echo "$game_id stored in games_array.\n";

        } else {
            echo "Warning: Arena data not found or invalid for game ID $game_id in tournament ID $tournament_id.\n";
            continue; // Skip to the next game
        }
    }

    echo "Finished processing games, starting OPDB search in batches...\n";

    // Process games_array in batches
    $results = [];
    for ($i = 0; $i < $total_games; $i += $batch_size) {
        $batch = array_slice($games_array, $i, $batch_size);
        $multiCurl = [];
        $mh = curl_multi_init();

        foreach ($batch as $key => $game) {
            $query = $game['game_name'];
            $multiCurl[$key] = curl_init();

            $api_token = 'grjaSqwjXHro2561oNURv1LUmfTisROncFtLONvPtzG4Ujg3I1zhJj43gMYU';
            $data = [
                'api_token' => $api_token,
                'q' => $query,
            ];

            $query_string = http_build_query($data);
            $url = 'https://opdb.org/api/search?' . $query_string;

            curl_setopt($multiCurl[$key], CURLOPT_URL, $url);
            curl_setopt($multiCurl[$key], CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($multiCurl[$key], CURLOPT_HTTPGET, 1);
            curl_setopt($multiCurl[$key], CURLOPT_TIMEOUT, 30); // 30 seconds
            curl_setopt($multiCurl[$key], CURLOPT_CONNECTTIMEOUT, 30); // 30 seconds

            curl_multi_add_handle($mh, $multiCurl[$key]);
        }

        // Execute the multi-cURL handles
        $index = null;
        do {
            curl_multi_exec($mh, $index);
        } while ($index > 0);

        // Retrieve the results for the current batch
        foreach ($multiCurl as $key => $ch) {
            $response = curl_multi_getcontent($ch);
            $response_data = json_decode($response, true);

            // Find and assign the opdb_name from the response
            if (isset($response_data['results']) && !empty($response_data['results'])) {
                // Assuming the first result is the most relevant
                $opdb_name = $response_data['results'][0]['name'];
                $games_array[$i + $key]['opdb_name'] = $opdb_name;
                echo "Processed game: {$batch[$key]['game_id']} - OPDB name found: $opdb_name.\n";
            } else {
                echo "No OPDB name found for game: {$batch[$key]['game_id']}.\n";
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        // Close the multi-cURL handle for the current batch
        curl_multi_close($mh);

        // Optional: Pause between batches to further reduce server load
        sleep(1); // Sleep for 1 second between batches
    }

    // At this point, $games_array contains game_id, game_name, and opdb_name for each game.
    echo "OPDB search completed for all batches.\n";



    // Batch process the stored data
    $conn = createConnection();
    $batch_size = 100000;
    $i = 1;

    $batches = array_chunk($games_array, $batch_size);
    $batchSize = count($batches);

    echo "Starting to update the database in $batchSize batches.\n";

    foreach ($batches as $batch) {
        $sql = "UPDATE games SET 
                    game_name = CASE game_id ";

        foreach ($batch as $game_data) {
            $sql .= "WHEN {$game_data['game_id']} THEN '{$conn->real_escape_string($game_data['game_name'])}' ";
        }

        $sql .= "END 
                WHERE game_id IN (" . implode(',', array_column($games_array, 'game_id')) . ")";

        if ($conn->query($sql) === TRUE) {
            echo "Batch $i of $batchSize updated successfully.\n";
            $i++;
        } else {
            echo "Error updating records: " . $conn->error . "\n";
        }
    }

    echo "All $total_games games processed and stored successfully.\n";
}



function getTournamentData($tournament_id) {
//Gets Tournament Data by tournamentId such as player IDs and Names
//  (
//             [tournamentId] => 138243
//             [name] => Tilt League 5-16-24
//             [status] => completed
//             [type] => group_matchplay
//             [startUtc] => 2024-05-17T01:30:00.000000Z
//             [startLocal] => 2024-05-16 18:30:00
//             [endUtc] => 2024-05-17T01:30:00.000000Z
//             [endLocal] => 2024-05-16 18:30:00
//             [completedAt] => 2024-05-17T03:35:59.000000Z
//             [organizerId] => 2465
//             [locationId] => 7138
//             [seriesId] => 3406
//             [description] => Tilt League - see https://playmorepinball.wordpress.com/
// 4 rounds of 4-player group match play
//             [pointsMap] => Array
//                 (
//                     [0] => Array
//                         (
//                             [0] => 7
//                         )

//                     [1] => Array
//                         (
//                             [0] => 7
//                             [1] => 1
//                         )

//                     [2] => Array
//                         (
//                             [0] => 7
//                             [1] => 4
//                             [2] => 1
//                         )

//                     [3] => Array
//                         (
//                             [0] => 7
//                             [1] => 5
//                             [2] => 3
//                             [3] => 1
//                         )

//                 )

//             [tiebreakerPointsMap] => Array
//                 (
//                     [0] => Array
//                         (
//                             [0] => 0.50
//                         )

//                     [1] => Array
//                         (
//                             [0] => 0.50
//                             [1] => 0.00
//                         )

//                     [2] => Array
//                         (
//                             [0] => 0.50
//                             [1] => 0.25
//                             [2] => 0.00
//                         )

//                     [3] => Array
//                         (
//                             [0] => 0.50
//                             [1] => 0.25
//                             [2] => 0.12
//                             [3] => 0.00
//                         )

//                 )

//             [test] => 
//             [timezone] => America/Phoenix
//             [scorekeeping] => user
//             [link] => 
//             [linkedTournamentId] => 
//             [estimatedTgp] => 
//             [organizer] => stdClass Object
//                 (
//                     [userId] => 2465
//                     [name] => John Shopple
//                     [firstName] => John
//                     [lastName] => Shopple
//                     [ifpaId] => 11590
//                     [role] => player
//                     [flag] => 
//                     [location] => Mesa, AZ
//                     [pronouns] => he
//                     [initials] => JPS
//                     [avatar] => 
//                     [banner] => 
//                     [tournamentAvatar] => https://mp-avatars.sfo3.cdn.digitaloceanspaces.com/t-avatar-U2465-1686564276.jpg
//                     [createdAt] => 2016-09-13T22:49:46.000000Z
//                 )

//             [players] => Array
//                 (
//                     [0] => stdClass Object
//                         (
//                             [playerId] => 148785
//                             [name] => John Shopple
//                             [ifpaId] => 11590
//                             [status] => active
//                             [organizerId] => 2465
//                             [claimedBy] => 2465
//                             [tournamentPlayer] => stdClass Object
//                                 (
//                                     [status] => active
//                                     [seed] => 0
//                                     [pointsAdjustment] => 0
//                                     [subscription] => 
//                                     [labels] => Array
//                                         (
//                                         )

//                                     [labelColor] => 
//                                 )

//                         )

//                     [1] => stdClass Object
//                         (
//                             [playerId] => 237410
//                             [name] => Paul Blanco
//                             [ifpaId] => 66005
//                             [status] => active
//                             [organizerId] => 2465
//                             [claimedBy] => 15883
//                             [tournamentPlayer] => stdClass Object
//                                 (
//                                     [status] => active
//                                     [seed] => 8
//                                     [pointsAdjustment] => 0
//                                     [subscription] => 
//                                     [labels] => Array
//                                         (
//                                         )
//      ---- Players Continued ---

//     [seeding] => random
//     [firstRoundPairing] => random
//     [pairing] => balanced_series
//     [playerOrder] => balanced
//     [arenaAssignment] => balanced
//     [duration] => 4
//     [gamesPerRound] => 1
//     [playoffsCutoff] => 0
//     [playoffsCutoffText] => FINALS CUTOFF
//     [playoffsCutoffColor] => red
//     [suggestions] => disabled
//     [tiebreaker] => disabled
//     [scoring] => ifpa
// )


    return matchplayRequest("https://app.matchplay.events/api/tournaments/$tournament_id?includePlayers=0&includeArenas=1");
}


function fetchAndStorePlayerFromTournament() {
    global $conn;
    // $tournament_ids = getAllTournamentIdsFromDb();
    $tournament_id = [99893];
    // Initialize an array to keep track of processed player IDs
    $processedPlayerIds = [];

    foreach ($tournament_ids as $tournament_id) {
        $data = getTournamentData($tournament_id);

        // Debug: print the raw data to check its structure
        echo "Data for tournament ID $tournament_id:\n";

        // Access the players array correctly
        if (isset($data['data']['players']) && is_array($data['data']['players'])) {
            foreach ($data['data']['players'] as $player) {
                $player_id = $player['playerId'];

                // Check if the player ID has already been processed
                if (in_array($player_id, $processedPlayerIds)) {
                    continue; // Skip this player and move to the next one
                }

                // Add the player ID to the processed list
                $processedPlayerIds[] = $player_id;

                $ifpa_id = isset($player['ifpaId']) ? $player['ifpaId'] : NULL;
                $organizer_id = isset($player['organizerId']) ? $player['organizerId'] : NULL;
                $claimed_by = isset($player['claimedBy']) ? $player['claimedBy'] : NULL;

                $sql = "INSERT INTO matchplay_players (player_id, organizer_id, ifpa_id, matchplay_profile_id) 
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                        organizer_id = VALUES(organizer_id), 
                        ifpa_id = VALUES(ifpa_id), 
                        matchplay_profile_id = VALUES(matchplay_profile_id)";

                // Prepare the SQL statement
                if ($stmt = $conn->prepare($sql)) {
                    // Bind parameters
                    $stmt->bind_param('iiii', $player_id, $organizer_id, $ifpa_id, $claimed_by);

                    // Execute the statement
                    if (!$stmt->execute()) {
                        echo "Error executing statement for player ID $player_id: " . $stmt->error . "\n";
                    } else {
                        echo "Successfully inserted player ID $player_id into the database.\n";
                    }

                    $stmt->close();
                } else {
                    echo "Error preparing statement: " . $conn->error . "\n";
                }
            }
        } else {
            echo "No players data found for tournament ID $tournament_id.\n";
        }
    }

    $conn->close();
}

function fetchAndStoreSpecificPlayerFromTournament() {
    global $conn;

    // Set the specific tournament ID and player ID
    $tournament_id = 99893;
    $target_player_id = 207671;
    
    // Initialize an array to keep track of processed player IDs
    $processedPlayerIds = [];

    // Fetch tournament data for the specific tournament ID
    $data = getTournamentData($tournament_id);

    // Debug: print the raw data to check its structure
    echo "Data for tournament ID $tournament_id:\n";

    // Access the players array correctly
    if (isset($data['data']['players']) && is_array($data['data']['players'])) {
        foreach ($data['data']['players'] as $player) {
            $player_id = $player['playerId'];

            // Only process the target player ID
            if ($player_id != $target_player_id) {
                continue; // Skip this player and move to the next one
            }

            // Check if the player ID has already been processed
            if (in_array($player_id, $processedPlayerIds)) {
                continue; // Skip this player and move to the next one
            }

            // Add the player ID to the processed list
            $processedPlayerIds[] = $player_id;

            $ifpa_id = isset($player['ifpaId']) ? $player['ifpaId'] : NULL;
            $organizer_id = isset($player['organizerId']) ? $player['organizerId'] : NULL;
            $claimed_by = isset($player['claimedBy']) ? $player['claimedBy'] : NULL;

            $sql = "INSERT INTO matchplay_players (player_id, organizer_id, ifpa_id, matchplay_profile_id) 
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    organizer_id = VALUES(organizer_id), 
                    ifpa_id = VALUES(ifpa_id), 
                    matchplay_profile_id = VALUES(matchplay_profile_id)";

            // Prepare the SQL statement
            if ($stmt = $conn->prepare($sql)) {
                // Bind parameters
                $stmt->bind_param('iiii', $player_id, $organizer_id, $ifpa_id, $claimed_by);

                // Execute the statement
                if (!$stmt->execute()) {
                    echo "Error executing statement for player ID $player_id: " . $stmt->error . "\n";
                } else {
                    echo "Successfully inserted player ID $player_id into the database.\n";
                }

                $stmt->close();
            } else {
                echo "Error preparing statement: " . $conn->error . "\n";
            }
        }
    } else {
        echo "No players data found for tournament ID $tournament_id.\n";
    }

    $conn->close();
}

function getRoundsData($tournament_id) {
//Provides list of rounds based on tournament_id
// Array
// (
//     [0] => stdClass Object
//         (
//             [roundId] => 653315
//             [tournamentId] => 138243
//             [index] => 0
//             [name] => Round 1
//             [duration] => 1723
//             [createdAt] => 2024-05-17 01:36:55Z
//             [completedAt] => 2024-05-17 02:05:38Z
//             [gameCount] => 7
//             [threePlayerGroupCount] => 1
//             [fourPlayerGroupCount] => 6
//         )

//     [1] => stdClass Object
//         (
//             [roundId] => 653344
//             [tournamentId] => 138243
//             [index] => 1
//             [name] => Round 2
//             [duration] => 1558
//             [createdAt] => 2024-05-17 02:06:08Z
//             [completedAt] => 2024-05-17 02:32:06Z
//             [gameCount] => 7
//             [threePlayerGroupCount] => 1
//             [fourPlayerGroupCount] => 6
//         )

//     [2] => stdClass Object
//         (
//             [roundId] => 653374
//             [tournamentId] => 138243
//             [index] => 2
//             [name] => Round 3
//             [duration] => 1816
//             [createdAt] => 2024-05-17 02:32:46Z
//             [completedAt] => 2024-05-17 03:03:02Z
//             [gameCount] => 7
//             [threePlayerGroupCount] => 0
//             [fourPlayerGroupCount] => 7
//         )

//     [3] => stdClass Object
//         (
//             [roundId] => 653403
//             [tournamentId] => 138243
//             [index] => 3
//             [name] => Round 4
//             [duration] => 1916
//             [createdAt] => 2024-05-17 03:04:03Z
//             [completedAt] => 2024-05-17 03:35:59Z
//             [gameCount] => 7
//             [threePlayerGroupCount] => 0
//             [fourPlayerGroupCount] => 7
//         )

// )

    return matchplayRequest("https://app.matchplay.events/api/tournaments/$tournament_id/stats/rounds");
}

function getRoundsForTournament($tournamentId) {
    $url = "https://app.matchplay.events/api/tournaments/{$tournamentId}/rounds";
    $data = matchplayRequest($url);

    if ($data === null || !isset($data['data']) || !is_array($data['data'])) {
        echo "Error fetching rounds for tournament ID $tournamentId\n";
        return [];
    }

    return $data['data'];
}


function fetchAndStoreRoundsForAllTournaments() {
    $tournamentIds = getAllTournamentIdsFromDb();

    foreach ($tournamentIds as $tournamentId) {
        echo "Fetching rounds for tournament ID: $tournamentId\n";
        $rounds = getRoundsForTournament($tournamentId);
        if (!empty($rounds)) {
            storeRoundsInDb($rounds);
        }
    }
}

function updateIfpaIds () {
    global $conn, $matchplay_ids;


    foreach  ($matchplay_ids as $matchplay_id){

        echo "Fetching and storing data for player ID: $matchplay_id\n";
        $data = getPlayerDetails($matchplay_id);

        if (isset($data['user']) && is_array($data['user'])) {
            $ifpa_id = $data['user']['ifpaId'] ?? null;
            $ifpa_data = getIfpaRanking($ifpa_id);

            if (isset($ifpa_data['player']) && is_array($ifpa_data['player'])) {
                foreach ($ifpa_data['player'] as $player) {
                    if (isset($player['player_stats']['ratings_rank'])) {
                        $ifpa_rank = $player['player_stats']['ratings_rank'];

                        $sql = "UPDATE player
                            SET player_id = ?, ifpa_rank = ?
                            WHERE matchplay_id = ?;";


                            //Prepare the SQL statement
                        if ($stmt = $conn->prepare($sql)) {
                            // Bind parameters to the SQL statement
                            $stmt->bind_param('iii', $ifpa_id, $ifpa_rank, $matchplay_id);
                            
                            //Execute the statement
                            if (!$stmt->execute()) {
                                echo "Error executing statement for player ID $ifpa_id: " . $stmt->error . "\n";
                            } else {
                                echo "Successfully updated player ID $ifpa_id \n";
                            }
                        }
                    }
                }
            }
        }
    }

$conn->close(); // Close the database connection

}



function getProfileDetails($claimed_id) {
// Returns Array of player info including name, IFPA ID, matchplay rank,
//     ?Array
// (
//     [user] => Array
//         (
//             [userId] => 2465
//             [name] => John Shopple
//             [firstName] => John
//             [lastName] => Shopple
//             [ifpaId] => 11590
//             [role] => player
//             [flag] => 
//             [location] => Mesa, AZ
//             [pronouns] => he
//             [initials] => JPS
//             [avatar] => 
//             [banner] => 
//             [tournamentAvatar] => https://mp-avatars.sfo3.cdn.digitaloceanspaces.com/t-avatar-U2465-1686564276.jpg
//             [createdAt] => 2016-09-13T22:49:46.000000Z
//         )

//     [rating] => Array
//         (
//             [ratingId] => 5112
//             [userId] => 2465
//             [ifpaId] => 11590
//             [name] => John Shopple
//             [rating] => 1772
//             [rd] => 29
//             [calculatedRd] => 29
//             [lowerBound] => 1713
//             [lastRatingPeriod] => 2024-05-24T00:00:00.000000Z
//             [rank] => 54
//         )

//     [ifpa] => 
//     [shortcut] => 
//     [plan] => 
//     [userCounts] => Array
//         (
//             [tournamentOrganizedCount] => 0
//             [seriesOrganizedCount] => 0
//             [tournamentPlayCount] => 0
//             [ratingPeriodCount] => 0
//         )
    return matchplayRequest("https://app.matchplay.events/api/users/$claimed_id");
}

function getGames ($tournament_id) {
return matchplayRequest("https://app.matchplay.events/api/tournaments/$tournament_id/games");
}



function fetchAndStoreGamesForAllTournaments($startTournamentId = null) {
    $tournamentIds = getAllTournamentIdsFromDb();
    $startFound = is_null($startTournamentId);

    foreach ($tournamentIds as $tournamentId) {

        echo "Fetching games for tournament ID: $tournamentId\n";
        $gamesData = getGames($tournamentId);
        if ($gamesData !== null && isset($gamesData['data']) && is_array($gamesData['data'])) {
            
            // Check if each game in $gamesData['data'] has at least two results in resultPositions
            $validGames = array_filter($gamesData['data'], function($game) {
                return isset($game['resultPositions']) && is_array($game['resultPositions']) && count($game['resultPositions']) >= 2;
            });

            // If there are valid games, store them in the database
            if (!empty($validGames)) {
                storeGamesInDb($validGames);
            } else {
                echo "No valid games data found for tournament ID $tournamentId\n";
            }
        } else {
            echo "No games data found for tournament ID $tournamentId\n";
        }
    }
}




function fetchAndStoreGamePlacements() {
    // Increase memory limit dynamically (adjust as needed)
    ini_set('memory_limit', '2G');  // Example: Increase to 2 GB

    // Fetch all game data from the database in batches
    global $matchplay_ids;
    global $tournament_ids;
    $batchSize = 100; // Number of records to process in each batch (adjust as needed)

    // Loop through gameData in batches
    for ($offset = 0; $offset < count($tournament_ids); $offset += $batchSize) {
        $batch = array_slice($tournament_ids, $offset, $batchSize);

        foreach ($batch as $tournamentId) {
            echo "Fetching game for tournament ID: $tournamentId\n";

            // Fetch game data from MatchPlay API
            $data = getTournamentData($tournamentId);

            // Check if data is not null
             if ($data === null) {
                echo "Error fetching game data for tournament ID: $tournamentId\n";
                continue;
            } else {

            // Collect player IDs from tournament data
            collectMatchplayIdsFromTournament($data);
            }
        }
        // Optionally unset batch variables to free up memory
        unset($batch);
    }

    for ($offset = 0; $offset < count($matchplay_ids); $offset += $batchSize) {
        $batch = array_slice($matchplay_ids, $offset, $batchSize);

        foreach ($batch as $matchplayId) {
            echo "Fetching game for tournament ID: $matchplayId\n";

            // Fetch game data from MatchPlay API
            $data = getPlayerDetails($matchplayId);
        }
    }
}

function StorePlayers() {
    global $matchplay_ids;
    global $conn;

    
    // foreach  ($matchplay_ids as $matchplay_id){

        echo "Fetching and storing data for player ID: $matchplay_ids\n";
        storePlayer($matchplay_ids);
        
    // }

$conn->close(); // Close the database connection

}




// Start IFPA  functions
function ifpaRequest($initial_url) {
    $api_key = 'e157f6e1c9a2ef9e3ef03acffd681204';
    $url = $initial_url . '?api_key=' . $api_key;

    // Initialize a cURL session
    $ch = curl_init();

    // Set the options for the cURL request
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);

    // Execute the cURL request and fetch the response
    $response = curl_exec($ch);

    // Check for errors
    if (curl_errno($ch)) {
        echo 'cURL error: ' . curl_error($ch);
        return null;
    }

    // Close the cURL session
    curl_close($ch);

    // Decode and return the JSON response
    return json_decode($response, true);
}

function getIfpaRanking($ifpa_id) {
// Returns IFPA player info including IFPA rank AS [ratings_rank], pro rank AS [pro_rank]
// Array
// (
//     [player] => Array
//         (
//             [0] => Array
//                 (
//                     [player_id] => 11590
//                     [first_name] => John
//                     [last_name] => Shopple
//                     [initials] => 
//                     [excluded_flag] => false
//                     [age] => 
//                     [city] => Mesa
//                     [stateprov] => AZ
//                     [country_name] => United States
//                     [country_code] => US
//                     [ifpa_registered] => true
//                     [womens_flag] => false
//                     [profile_photo] => 
//                     [matchplay_events] => Array
//                         (
//                             [id] => 2465
//                             [rating] => 1778
//                             [rank] => 23
//                         )

//                     [player_stats] => Array
//                         (
//                             [current_wppr_rank] => 88
//                             [last_month_rank] => 91
//                             [last_year_rank] => 145
//                             [pro_rank] => 73
//                             [highest_rank] => 89
//                             [highest_rank_date] => 2024-06-01
//                             [current_wppr_points] => 829.65
//                             [all_time_wppr_points] => 3677.60
//                             [active_wppr_points] => 2819.3500
//                             [inactive_wppr_points] => 858.2500
//                             [best_finish] => 1
//                             [best_finish_count] => 57
//                             [average_finish] => 13
//                             [average_finish_last_year] => 10
//                             [total_events_all_time] => 404
//                             [total_active_events] => 188
//                             [total_events_away] => 0
//                             [total_wins_last_3_years] => 42
//                             [top_3_last_3_years] => 89
//                             [top_10_last_3_years] => 143
//                             [ratings_rank] => 85
//                             [ratings_value] => 1816.93
//                             [efficiency_rank] => 385
//                             [efficiency_value] => 40.860
//                             [years_active] => 12
//                         )

//                     [series] => Array
//                         (
//                             [0] => Array
//                                 (
//                                     [series_code] => NACS
//                                     [region_code] => AZ
//                                     [region_name] => Arizona
//                                     [year] => 2022
//                                     [total_points] => 405.27
//                                     [series_rank] => 3
//                                 )

//                             [1] => Array
//                                 (
//                                     [series_code] => NACS
//                                     [region_code] => OK
//                                     [region_name] => Oklahoma
//                                     [year] => 2022
//                                     [total_points] => 11.74
//                                     [series_rank] => 49
//                                 )

//                             [2] => Array
//                                 (
//                                     [series_code] => NACS
//                                     [region_code] => CA
//                                     [region_name] => California
//                                     [year] => 2022
//                                     [total_points] => 29.70
//                                     [series_rank] => 181
//                                 )


return ifpaRequest("https://api.ifpapinball.com/v2/player/$ifpa_id");
}

function getIfpaRankingList() {

return ifpaRequest("https://api.ifpapinball.com/v2/rankings/wppr");

}

function fetchAndStorePlayers() {
    global $conn;
    $players = getIdsFromMatchplayPlayers(); // Retrieve player IDs
    $player_count = count($players);

    // Prepare an array to hold the data to be inserted
    $playerDataArray = [];

    $i = 1;
    foreach ($players as $player) {
        $ifpa_id = $player['ifpa_id'];
        $matchplay_id = $player['matchplay_profile_id'];

        $matchplay_rating = null;
        $first_name = null;
        $last_name = null;
        $ifpa_rank = null;

        // Fetch IFPA data
        $ifpa_data = getIfpaRanking($ifpa_id);

        if (isset($ifpa_data['player']) && is_array($ifpa_data['player'])) {
            $player_data = $ifpa_data['player'];
            $first_name = isset($player_data['first_name']) ? $player_data['first_name'] : null;
            $last_name = isset($player_data['last_name']) ? $player_data['last_name'] : null;
            $ifpa_rank = isset($player_data['player_stats']['ratings_rank']) ? $player_data['player_stats']['ratings_rank'] : null;
        } else {
            echo "Warning: IFPA data for player with IFPA ID $ifpa_id is missing or incomplete.\n";
        }

        // Fetch Matchplay data if matchplay_id is available
        if (!empty($matchplay_id)) {
            $matchplay_data = getProfileDetails($matchplay_id);

            if (isset($matchplay_data['rating']) && is_array($matchplay_data['rating'])) {
                $matchplay_rating = $matchplay_data['rating']['rating'];
            } else {
                echo "Warning: Matchplay data for player with Matchplay ID $matchplay_id is missing or incomplete.\n";
            }
        }

        // Collect the data for batch insertion
        $playerDataArray[] = [
            'ifpa_id' => $ifpa_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'ifpa_rank' => $ifpa_rank,
            'matchplay_profile_id' => $matchplay_id,
            'matchplay_rating' => $matchplay_rating
        ];

        echo "$ifpa_id stored in playerDataArray. \nPlayer $i out of $player_count \n";
        $i++;
    }

    echo "Preparing SQL statement\n";
    $conn = createConnection();

    // Prepare the SQL statement for batch insertion
    $sql = "INSERT INTO players (ifpa_id, first_name, last_name, ifpa_rank, matchplay_profile_id, matchplay_rating)
            VALUES (?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);

    // Execute the batch insertion
    foreach ($playerDataArray as $playerData) {
        $stmt->bind_param(
            "issiii",
            $playerData['ifpa_id'],
            $playerData['first_name'],
            $playerData['last_name'],
            $playerData['ifpa_rank'],
            $playerData['matchplay_profile_id'],
            $playerData['matchplay_rating']
        );
        if (!$stmt->execute()) {
            echo "Error executing statement: " . $stmt->error . "\n";
        }
    }

    echo "Data inserted into the database\n";
    $stmt->close();
    $conn->close();
}

function fetchAndStoreIfpaPlayers() {
    $players = getIfpaIdsFromPlayers();
    $player_count = count($players);
    $BATCH_SIZE = 20;  // Adjust this to the desired number of concurrent requests

    $playerDataArray = [];
    $multi_handle = curl_multi_init();

    // Initialize batch counter
    $batch_counter = 1;
    $total_batches = ceil($player_count / $BATCH_SIZE);

    // Process players in batches
    for ($i = 0; $i < $player_count; $i += $BATCH_SIZE) {
        $batch = array_slice($players, $i, $BATCH_SIZE);
        $curl_handles = [];

        echo "Processing batch $batch_counter of $total_batches\n";

        // Initialize and add handles to the multi-handle for the current batch
        foreach ($batch as $player) {
            $ifpa_id = $player; // Ensure this is a scalar value (string or integer)
            $api_key = 'e157f6e1c9a2ef9e3ef03acffd681204';
            $url = "https://api.ifpapinball.com/v2/player/$ifpa_id?api_key=$api_key";

            // Initialize a cURL session
            $ch = curl_init();

            // Set the options for the cURL request
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);

            // Store the handle
            $curl_handles[$player] = $ch;
            curl_multi_add_handle($multi_handle, $ch);
        }

        // Execute the multi-handle for the current batch
        $running = null;
        do {
            curl_multi_exec($multi_handle, $running);
            curl_multi_select($multi_handle);
        } while ($running);

        // Process the responses for the current batch
        foreach ($batch as $player) {
            $response = curl_multi_getcontent($curl_handles[$player]);
            $ifpa_data = json_decode($response, true);

            if (isset($ifpa_data['player'][0]) && is_array($ifpa_data['player'][0])) {
                $player_data = $ifpa_data['player'][0];  // Access the first player array
                $ifpa_rank = isset($player_data['player_stats']['current_wppr_rank']) ? $player_data['player_stats']['current_wppr_rank'] : null;


                $playerDataArray[] = [
                    'ifpa_id' => $player,
                    'ifpa_rank' => $ifpa_rank
                ];

                echo "$player rank $ifpa_rank stored in playerDataArray. \n";
            } else {
                echo "Warning: IFPA data for player with IFPA ID $player is missing or incomplete.\n";
            }

            // Remove and close the handle
            curl_multi_remove_handle($multi_handle, $curl_handles[$player]);
            curl_close($curl_handles[$player]);
        }

        // Increment the batch counter
        $batch_counter++;
    }

    // Close the multi-handle after all batches are processed
    curl_multi_close($multi_handle);

    echo "Preparing SQL statement\n";
    $conn = createConnection();

    $sql = "UPDATE players 
            SET ifpa_rank = ? 
            WHERE ifpa_id = ?";

    $stmt = $conn->prepare($sql);

    foreach ($playerDataArray as $playerData) {
        echo "Updating IFPA ID: " . $playerData['ifpa_id'] . "\n";

        $stmt->bind_param(
            "ii",  // 's' for string, 'i' for integer
            $playerData['ifpa_rank'],
            $playerData['ifpa_id']
        );

        if (!$stmt->execute()) {
            echo "Error executing statement: " . $stmt->error . "\n";
        } else {
            echo "Data updated successfully for IFPA ID: " . $playerData['ifpa_id'] . "\n";
        }
    }

    echo "Data updated in the database\n";
    $stmt->close();
    $conn->close();
}




function opdbSearch($query) {
    $api_token = 'grjaSqwjXHro2561oNURv1LUmfTisROncFtLONvPtzG4Ujg3I1zhJj43gMYU';

    // Define the POST data
    $data = [
        'api_token' => $api_token,
        'q' => $query,
    ];

    // Construct the query string
    $query_string = http_build_query($data);

    // Construct the full URL
    $url = 'https://opdb.org/api/search?' . $query_string;

    // Initialize cURL session
    $ch = curl_init();

    // Set the URL and other options for a GET request
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPGET, 1);

    // Increase timeout settings
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 seconds
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30); // 30 seconds

    // Execute the request
    $response = curl_exec($ch);

    // Check for errors
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    } else {
        // Decode the response if it's JSON
        $response_data = json_decode($response, true);
        return $response_data;
    }

    // Close the cURL session
    curl_close($ch);
}

function opdbSearchMultiCurl($queries) {
    $multiCurl = [];
    $results = [];
    $mh = curl_multi_init();

    // Initialize cURL handles for each query
    foreach ($queries as $query) {
        $ch = opdbSearch($query);
        curl_multi_add_handle($mh, $ch);
        $multiCurl[] = ['ch' => $ch, 'query' => $query];
    }

    // Execute all requests in parallel
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($status > 0) {
            echo "cURL error: " . curl_multi_strerror($status) . "\n";
        }

        // Check for completed requests
        while ($completed = curl_multi_info_read($mh)) {
            $handle = $completed['handle'];
            $response = curl_multi_getcontent($handle);
            curl_multi_remove_handle($mh, $handle);
            curl_close($handle);

            foreach ($multiCurl as $key => $request) {
                if ($request['ch'] === $handle) {
                    $response_data = json_decode($response, true);
                    if ($response_data) {
                        $results[$request['query']] = isset($response_data[0]['name']) ? $response_data[0]['name'] : null;
                    } else {
                        echo "Failed to decode JSON for query: {$request['query']}. Response: $response\n";
                        $results[$request['query']] = null;
                    }

                    unset($multiCurl[$key]);
                    break;
                }
            }
        }

        // Sleep to reduce CPU usage
        usleep(100000);
    } while ($running > 0 && !empty($multiCurl));

    // Close the multi handle
    curl_multi_close($mh);

    return $results;
}

