<?php
    $tournament_ids = [];


function createConnection() { 
    $conn = mysqli_connect('localhost:3307', 'root', '', 'pinball_api');

    // Check connection
    if (!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }
    return $conn;
}


function processAndUpdateArenaData(&$arena_map){
    $conn = createConnection();

    // Prepare the SQL statement for batch updating
    $sql = "UPDATE arenas SET game_name = ? WHERE arena_id = ?";

    if ($stmt = $conn->prepare($sql)) {
        // Execute the batch update
        foreach ($arena_map as $arena_data) {
            // Ensure game_name is not an empty string
            if (!empty($arena_data['game_name'])) {
                // Log SQL statement for debugging
                $stmt->bind_param(
                    "si",  // Ensure these match the types in your database schema
                    $arena_data['game_name'],
                    $arena_data['arena_id']
                );
                if (!$stmt->execute()) {
                    echo "Error executing statement: " . $stmt->error . "\n";
                } else {
                    // echo "Updated arena_id: {$arena_data['arena_id']} with game_name: {$arena_data['game_name']}.\n";
                }
            } else {
                echo "Skipping arena_id: {$arena_data['arena_id']} due to empty game_name.\n";
            }
        }

        $stmt->close();
    } else {
        echo "Error preparing statement: " . $conn->error . "\n";
    }
    $conn->close();  // Close the connection after updating the database

}

function storeTournamentsInDb($tournaments) {
    global $conn;
    
    // SQL statement with placeholders for binding parameters
    $sql = "INSERT INTO tournaments (tournament_id, organizer_id, series_id, type) 
            VALUES (?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
                organizer_id = VALUES(organizer_id),
                series_id = VALUES(series_id),
                type = VALUES(type)";

    // Prepare the SQL statement
    if ($stmt = $conn->prepare($sql)) {
        // Iterate over each tournament in the array
        foreach ($tournaments as $tournament) {
            // Check if required fields are set
            if (isset($tournament['tournamentId'],  $tournament['organizerId'], $tournament['type'])) {
                // Extract values
                $tournament_id = $tournament['tournamentId'];
                $organizer_id = $tournament['organizerId'];
                $series_id = isset($tournament['seriesId']) ? $tournament['seriesId'] : null;
                $type = $tournament['type'];

                // Bind parameters to the SQL statement
                // 'i' for integer, 's' for string, 'ssi' means series_id can be NULL
                if ($series_id === null) {
                    $stmt->bind_param('iiis', $tournament_id, $organizer_id, $series_id, $type);
                } else {
                    $stmt->bind_param('iiis', $tournament_id, $organizer_id, $series_id, $type);
                }
                
                // Execute the statement
                if (!$stmt->execute()) {
                    echo "Error executing statement for tournament ID $tournament_id: " . $stmt->error . "\n";
                }
            } else {
                echo "Warning: Missing required data in tournament record: ";
                print_r($tournament);
            }
        }
        $stmt->close(); // Close the statement
    } else {
        echo "Error preparing statement: " . $conn->error;
    }

}

function storePlayer($matchplay_id) {
    global $conn, $matchplay_ids;

    $data = getPlayerDetails($matchplay_id);
    // Check if 'data' key exists and is an array
    if (isset($data['user']) && is_array($data['user'])) {
        $first_name = $data['user']['firstName'] ?? null;
        $last_name = $data['user']['lastName'] ?? null;
        $ifpa_id = $data['user']['ifpaId'] ?? null;

        if (isset($data['rating']) && is_array($data['rating'])) {
            $matchplay_rank = $data['rating']['rank'] ?? null;
            $ifpa_data = getIfpaRanking($ifpa_id);

            if (isset($ifpa_data['player']) && is_array($ifpa_data['player'])) {
                foreach ($ifpa_data['player'] as $player) {
                    if (isset($player['player_stats']['ratings_rank'])) {
                        $ifpa_rank = $player['player_stats']['ratings_rank'];

                        $sql = "INSERT INTO player (player_id, matchplay_id, first_name, last_name, ifpa_rank, matchplay_rank) 
                                VALUES (?, ?, ?, ?, ?, ?)";

                        // Prepare the SQL statement
                        if ($stmt = $conn->prepare($sql)) {
                            // Bind parameters to the SQL statement
                            $stmt->bind_param('iissii', $ifpa_id, $matchplay_id, $first_name, $last_name, $ifpa_rank, $matchplay_rank);
                            
                            // Execute the statement
                            if (!$stmt->execute()) {
                                echo "Error executing statement for player ID $ifpa_id: " . $stmt->error . "\n";
                            } else {
                                echo "Successfully inserted player ID $ifpa_id into the database.\n";
                            }
                        }
                    }
                }
            }
        }
    }
}

function getIdsFromMatchplayPlayers() {
    $conn = createConnection();
    $player_ids = [];

    // $sql = "SELECT DISTINCT ifpa_id, matchplay_profile_id
    //     FROM matchplay_players WHERE ifpa_id IS NOT NULL AND ifpa_id < 10
    //     ORDER BY ifpa_id";

    $sql = "SELECT DISTINCT ifpa_id, matchplay_profile_id
        FROM matchplay_players
        WHERE ifpa_id IN ( 91, 491, 493, 536, 1531, 2753, 2767, 2922, 3532, 5233, 7534, 7807, 7809, 8560, 8851, 8855, 10662, 10751, 12117, 12125, 12217, 12220, 12378, 13052, 13275,
        14184, 14319, 14560, 15923, 15925, 15943, 16093, 16767, 16776, 16781, 17659, 19108, 19300, 19301, 19302, 19305, 19309, 19312, 20981, 21250, 21539,
        21965, 22178, 22189, 22195, 22573, 22577, 22768, 23707, 24604, 25457, 25460, 25462, 25464, 25465, 25476, 25487, 25494, 25696, 26157, 26161, 26297, 26698,
        28117, 28189, 29804, 29806, 29807, 29814, 30079, 30905, 34242, 34343, 43257, 43650, 52531, 56435, 57589, 58391, 68189, 71082, 74429, 75566, 78208, 81647,
        83287, 83408, 85318, 87422, 88073, 89826, 91167, 92884, 92956, 93304, 94699, 95691, 97961, 100236, 100336, 102099, 102915, 103565, 103949, 104157, 106630,
        107050, 107153, 107814, 107957, 108725, 108779, 109559, 110037, 110648, 114263, 119753, 121404)
        ORDER BY ifpa_id";

    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $player_ids[] = [
                'ifpa_id' => $row['ifpa_id'],
                'matchplay_profile_id' => $row['matchplay_profile_id']
            ];
            echo "Player ID: {$row['ifpa_id']} Retrieved. \n";
        }
    }
    $conn->close();
    return $player_ids;
}


function getIfpaIdsFromPlayers() {
    $conn = createConnection();
    $player_ids = [];

    $sql = "SELECT DISTINCT ifpa_id
        FROM players WHERE ifpa_rank IS NULL
        ORDER BY ifpa_id";

    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $player_ids[] = $row['ifpa_id'];  // Store only the ifpa_id directly
            echo "Player ID: {$row['ifpa_id']} Retrieved. \n";
        }
    }
    $conn->close();
    return $player_ids;  // Return a flat array of ifpa_id values
}


function getMatchplayIdsFromGame() {
    global $conn, $matchplay_ids;

    // $offset = 0;
    do {
        // SQL query to select unique matchplay_ids from first, second, third, and fourth columns in batches
        $sql = "
            SELECT DISTINCT unique_ids.matchplay_id
                FROM (
                    SELECT first AS matchplay_id FROM game
                    UNION
                    SELECT second AS matchplay_id FROM game
                    UNION
                    SELECT third AS matchplay_id FROM game
                    UNION
                    SELECT fourth AS matchplay_id FROM game
                ) AS unique_ids
                LEFT JOIN player ON unique_ids.matchplay_id = player.matchplay_id
                WHERE player.matchplay_id IS NULL
                
             ";
// LIMIT $batchSize OFFSET $offset;
        $result = mysqli_query($conn, $sql);
        $batchCount = 0;

        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $matchplay_id = $row['matchplay_id'];
                if (!in_array($matchplay_id, $matchplay_ids)) {
                    $matchplay_ids[] = $matchplay_id;
                    echo "Storing unique player ID: $matchplay_id\n";
                }
                $batchCount++;
            }
        } else {
            echo "Error retrieving data: " . mysqli_error($conn) . "\n";
        }

        // // Update offset
        // $offset += $batchSize;

        // // Break if no more data
        // if ($batchCount < $batchSize) {
        //     break;
        // }

        // // Optionally unset variables to free up memory
        // unset($result);

    } while (true);
}


function getMatchplayIdsFromPlayer($batchSize = 1000) {
    global $conn, $matchplay_ids;

    $offset = 0;
    do {
        $sql = "
            SELECT matchplay_id
            FROM player
            WHERE matchplay_id = player_id
            LIMIT $batchSize OFFSET $offset
        ";

        $result = mysqli_query($conn, $sql);
        $batchCount = 0;

        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $matchplayId = $row['matchplay_id'];
                if (!in_array($matchplayId, $matchplay_ids)) {
                    $matchplay_ids[] = $matchplayId;
                    echo "Storing matchplay ID: $matchplayId\n";
                }
                $batchCount++;
            }
        } else {
            echo "Error retrieving data: " . mysqli_error($conn) . "\n";
        }

        // Update offset
        $offset += $batchSize;

        // Break if no more data
        if ($batchCount < $batchSize) {
            break;
        }

        // Optionally unset variables to free up memory
        unset($result);

    } while (true);
}



function getTournamentIds() {
    $conn = createConnection();
    $tournaments = [];

    $sql = "
        SELECT DISTINCT tournament_id
        FROM games WHERE game_name IS NULL 
        ORDER BY tournament_id";

    $result = mysqli_query($conn, $sql);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $tournament_id = $row['tournament_id'];
            if (!in_array($tournament_id, $tournaments)) {
                $tournaments[] = $tournament_id;
                echo "Storing tournament ID: $tournament_id in list\n";
            }
        }
    } else {
        echo "Error retrieving data: " . mysqli_error($conn) . "\n";
    }
    return $tournaments;
}

function getAllGameIds() {
    $conn = createConnection();

    $games = [];

    $query = "SELECT DISTINCT game_name 
            FROM games 
            WHERE opdb_name IS NULL";
            // -- AND game_name REGEXP '^[E-Z]' ORDER BY game_name" 
            

        // AND game_id > 000000 

    if ($stmt = $conn->prepare($query)) {
        $stmt->execute();
        $stmt->store_result(); // Store the result set to get the number of rows

        $stmt->bind_result($game_name);

        // Fetch each row one by one
        while ($stmt->fetch()) {
            $games[] = [
                'game_name' => $game_name
            ];
            echo "$game_name stored in list \n";
        }

        $stmt->free_result(); // Free the result set
        $stmt->close(); // Close the statement
    } else {
        echo "Error preparing statement: " . $conn->error;
    }
    $conn->close();
    return $games;
}


function getArenaIds() {
    $conn = createConnection();
    $arenas = [];

    $query = "SELECT arena_id, 
                     MAX(tournament_id) AS tournament_id, 
                     MAX(game_name) AS game_name
              FROM games
              WHERE arena_id IS NOT NULL
              GROUP BY arena_id
              ORDER BY arena_id ASC";

    $result = $conn->query($query);

    if ($result->num_rows > 0) {
        $seen_tournaments = [];

        while ($row = $result->fetch_assoc()) {
            // Check if this tournament_id has already been seen
            if (!in_array($row['tournament_id'], $seen_tournaments)) {
                // Save the arena_id, tournament_id, and game_name if the tournament_id is unique
                $arenas[] = [
                    'arena_id' => $row['arena_id'],
                    'tournament_id' => $row['tournament_id'],
                    'game_name' => $row['game_name']
                ];

                // Mark this tournament_id as seen
                $seen_tournaments[] = $row['tournament_id'];
            }
        }
    } else {
        echo "No results found.\n";
    }

    $conn->close();
    return $arenas;
}


function updateArenas(){
    $conn = createConnection();
    $arenas = [];

    $query1 = "SELECT DISTINCT g.arena_id
                FROM arenas a
                LEFT JOIN games g ON a.arena_id = g.arena_id
                WHERE a.game_name IS NULL"; 

    $result = $conn->query($query1);

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Add the arena_id to the arenas array
            $arenas[] = $row['arena_id'];
            echo $row['arena_id'] . " added to arenas array\n";
        }
    } else {
        echo "No results found.\n";
    }

    echo "Initial statement executed and results gathered \n";

    // Ensure arena_ids are formatted correctly
    $arena_ids = implode(',', array_map(function($id) {
        return (int) $id; // If arena_id is integer
        // return "'" . (int)$id . "'"; // Uncomment this if arena_id is a string
    }, $arenas));

    echo "Arena IDs: $arena_ids\n";
    echo "arenas imploded \n";

    $game_names = [];

    echo "initiating query2\n";

    // Assuming $arena_ids is already defined or populated elsewhere in your code
    $query2 = "SELECT DISTINCT arena_id, game_name
               FROM games
               WHERE game_name IS NOT NULL AND arena_id IN ($arena_ids);";

    $result = $conn->query($query2);

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Add the arena_id and game_name to the game_names array
            $game_names[$row['arena_id']] = $row['game_name'];

            echo $row['arena_id'] . " and " . $row['game_name'] . " added to game_names array\n";
        }
    } else {
        echo "No results found.\n";
    }
    echo "all results retrieved \n";
}




function storeRoundsInDb($rounds) {
    global $conn;

    $sql = "INSERT INTO round (round_id, tournament_id, round_index) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
                tournament_id = VALUES(tournament_id),
                round_index = VALUES(round_index)";

    if ($stmt = $conn->prepare($sql)) {
        foreach ($rounds as $round) {
            if (isset($round['roundId'], $round['tournamentId'], $round['index'])) {
                $round_id = $round['roundId'];
                $tournament_id = $round['tournamentId'];
                $round_index = $round['index'];

                $stmt->bind_param('iii', $round_id, $tournament_id, $round_index);
                if (!$stmt->execute()) {
                    echo "Error executing statement for round ID $round_id: " . $stmt->error . "\n";
                }
            } else {
                echo "Warning: Missing required data in round record: ";
                print_r($round);
            }
        }
        $stmt->close();
    } else {
        echo "Error preparing statement: " . $conn->error;
    }

    $conn->close();
}


function storeGamesInDb($games) {
    global $conn; // Use the global connection

    $sql = "INSERT INTO games (game_id, round_id, tournament_id, duration, first, second, third, fourth) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
                round_id = VALUES(round_id),
                tournament_id = VALUES(tournament_id),
                duration = VALUES(duration),
                first = VALUES(first),
                second = VALUES(second),
                third = VALUES(third),
                fourth = VALUES(fourth)";

    if ($stmt = $conn->prepare($sql)) {
        foreach ($games as $game) {
            // Validate required fields
            if (isset($game['gameId'], $game['roundId'], $game['tournamentId'], $game['resultPositions'])) {
                $game_id = $game['gameId'];
                $round_id = $game['roundId'];
                $tournament_id = $game['tournamentId'];
                $duration = isset($game['duration']) ? $game['duration'] : null;

                // Ensure resultPositions has at least 4 values
                $first = isset($game['resultPositions'][0]) ? $game['resultPositions'][0] : null;
                $second = isset($game['resultPositions'][1]) ? $game['resultPositions'][1] : null;
                $third = isset($game['resultPositions'][2]) ? $game['resultPositions'][2] : null;
                $fourth = isset($game['resultPositions'][3]) ? $game['resultPositions'][3] : null;

                if ($duration !== null) {
                    $stmt->bind_param('iiiiiiii', $game_id, $round_id, $tournament_id, $duration, $first, $second, $third, $fourth);
                    if (!$stmt->execute()) {
                        echo "Error executing statement for game ID $game_id: " . $stmt->error . "\n";
                    }
                } else {
                    echo "Warning: Duration missing for game ID $game_id\n";
                }
                echo "Stored data for Game ID: $game_id";
            } else {
                // Log which fields are missing
                $missingFields = [];
                if (!isset($game['gameId'])) $missingFields[] = 'gameId';
                if (!isset($game['roundId'])) $missingFields[] = 'roundId';
                if (!isset($game['tournamentId'])) $missingFields[] = 'tournamentId';
                if (!isset($game['resultPositions'])) $missingFields[] = 'resultPositions';

                echo "Warning: Missing required data in game record: " . implode(', ', $missingFields) . "\n";
            }
        }

        $stmt->close();
    } else {
        echo "Error preparing statement: " . $conn->error;
    }
}


function storeResultPositions($gameId, $resultPositions) {
    global $conn;

    if (!is_array($resultPositions)) {
        echo "Error: resultPositions is not an array for game ID $gameId\n";
        echo "Type of resultPositions: " . gettype($resultPositions) . "\n";
        echo "Content of resultPositions: " . print_r($resultPositions, true) . "\n";
        return;
    }

    if (count($resultPositions) < 2) {
        echo "Insufficient result positions for game ID $gameId\n";
        return;
    }

    $first = $resultPositions[0];
    $second = $resultPositions[1];
    $third = isset($resultPositions[2]) ? $resultPositions[2] : null;
    $fourth = isset($resultPositions[3]) ? $resultPositions[3] : null;

    $sql = "UPDATE game SET first = ?, second = ?, third = ?, fourth = ? WHERE game_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        echo "Error preparing statement: " . $conn->error . "\n";
        return;
    }

    $stmt->bind_param("iiiii", $first, $second, $third, $fourth, $gameId);

    if (!$stmt->execute()) {
        echo "Error executing statement: " . $stmt->error . "\n";
    } else {
        echo "Updated result positions for game ID $gameId\n";
    }

    $stmt->close();
}

function storePlayerIdsInDb($playerIds) {
    global $conn; // Use the global connection

    $sql = "INSERT INTO player (matchplay_id) VALUES (?) 
            ON DUPLICATE KEY UPDATE matchplay_id = match_id";

    if ($stmt = $conn->prepare($sql)) {
        foreach ($playerIds as $player_id) {
            $stmt->bind_param('i', $player_id);
            if (!$stmt->execute()) {
                echo "Error inserting player ID $player_id: " . $stmt->error . "\n";
            }
        }
        $stmt->close();
    } else {
        echo "Error preparing statement: " . $conn->error . "\n";
    }
}

function updateOpdbName ($game_name, $opdb_name) {
    global $conn;

    $sql = "UPDATE games 
            SET opdb_name = ?
            WHERE game_name = ?";

    if ($stmt = $conn->prepare($sql)) {
        // Bind parameters
        $stmt->bind_param('ss', $opdb_name, $game_name);

        // Execute the statement
        if ($stmt->execute()) {
            echo "Record updated successfully.\n";
        } else {
            echo "Error executing statement: " . $stmt->error . "\n";
        }

        $stmt->close();

    } else {
        echo "Error preparing statement: " . $conn->error . "\n";
    }

}


// updateOpdbName ('Medieval Madness (Remake Royal Edition)', 'Medieval Madness (Remake Royal Edition)');
