<pre>
<?php
include 'functions/storageDB.php';
include 'functions/fetchDataQueries.php';
ini_set('memory_limit', '2G');


// $tournament_id = '138243';
// $game_id = '4348941';

// print_r(getTournamentData(131964));

// print_r(getGamesList(131964));
// fetchAndStoreArena();

// print_r(getGameData(99769,3012440));
// $games = getAllGameIds();

// print_r(count($games));

// $tournaments = getAllTournamentIdsFromDb();
// print_r(count($tournaments));

// fetchAndStoreGameArena();
// fetchAndStoreOPDB();

// fetchAndStoreIfpaPlayers();
fetchAndStoreSpecificPlayerFromTournament();
?>
</pre>