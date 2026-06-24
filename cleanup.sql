
SET FOREIGN_KEY_CHECKS = 0; -- to avoid any issues with constraints when clearing tables (search this online)
-- I believe default is no action (so this must be done)

DROP TABLE IF EXISTS dbl_ranking;
DROP TABLE IF EXISTS ranking;
DROP TABLE IF EXISTS team_match;
DROP TABLE IF EXISTS match_player;
DROP TABLE IF EXISTS `match`; -- match seeems to be a reserved command/word in MariaDB MySQL
DROP TABLE IF EXISTS doubles_team;
DROP TABLE IF EXISTS edition;
DROP TABLE IF EXISTS tournament;
DROP TABLE IF EXISTS player;

SET FOREIGN_KEY_CHECKS = 1;