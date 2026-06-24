
-- SET @data_dir = '/home/abeliwi1/Databases315/project/clean_data/';   -- ← UPDATE THIS
SET FOREIGN_KEY_CHECKS = 0;
-- DELETE FROM ranking;
-- DELETE FROM player;
-- DELETE FROM tournament;
-- DELETE FROM edition;
-- DELETE FROM doubles_team;
-- DELETE FROM match_player;
-- DELETE FROM team_match;
-- DELETE FROM `match`;

-- Players table
LOAD DATA LOCAL INFILE '/home/abeliwi1/Databases315/project/clean_data/players.csv'
INTO TABLE player
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n'
(player_id, full_name, nationality, birthdate, hand, height_cm);

-- Tournaments tab;le
LOAD DATA LOCAL INFILE '/home/abeliwi1/Databases315/project/clean_data/tournaments.csv'
INTO TABLE tournament
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n'
(tourn_id, name, surface, level, location);

-- Editions table
LOAD DATA LOCAL INFILE '/home/abeliwi1/Databases315/project/clean_data/editions.csv'
INTO TABLE edition
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n'
(tourn_id, year, prize_money, draw_size);

-- Doubles Teams table
LOAD DATA LOCAL INFILE '/home/abeliwi1/Databases315/project/clean_data/doubles_teams.csv'
INTO TABLE doubles_team
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n'
(team_id, player1_id, player2_id, start_date, end_date);

-- Matches table
LOAD DATA LOCAL INFILE '/home/abeliwi1/Databases315/project/clean_data/matches.csv'
INTO TABLE `match`
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n'
(match_id, tourn_id, edition_year, score, round, match_date, match_type, num_sets);

-- Match Player
LOAD DATA LOCAL INFILE '/home/abeliwi1/Databases315/project/clean_data/match_player.csv'
INTO TABLE match_player
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n'
(match_id, player_id, result, seed,
 aces, double_faults, serve_pts, first_in, first_won, second_won,
 bp_saved, bp_faced);

-- Team Matches
LOAD DATA LOCAL INFILE '/home/abeliwi1/Databases315/project/clean_data/team_matches.csv'
INTO TABLE team_match
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n'
(match_id, team_id, result);

-- Rankings
LOAD DATA LOCAL INFILE '/home/abeliwi1/Databases315/project/clean_data/rankings.csv'
INTO TABLE ranking
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n'
(player_id, rank_date, rank_pos, rank_pts);

SET FOREIGN_KEY_CHECKS = 1;

-- Personal'Sanity check
SELECT 'player' AS tbl, COUNT(*) AS `rows` FROM player -- backticks to avvoid collision w/ reserved mysql syntax words
UNION ALL
SELECT 'tournament', COUNT(*) FROM tournament
UNION ALL
SELECT 'edition', COUNT(*) FROM edition
UNION ALL
SELECT 'match', COUNT(*) FROM `match`
UNION ALL
SELECT 'match_player', COUNT(*) FROM match_player
UNION ALL
SELECT 'doubles_team', COUNT(*) FROM doubles_team
UNION ALL
SELECT 'team_match', COUNT(*) FROM team_match
UNION ALL
SELECT 'ranking', COUNT(*) FROM ranking;

-- Verify both tours are present
SELECT LEFT(player_id, 1) AS tour, COUNT(*) AS `rows`
FROM ranking
GROUP BY LEFT(player_id, 1);
-- Should show: A (ATP) and W (WTA)