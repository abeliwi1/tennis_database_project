/* Creating tables for DB */

-- PLAYER: one row per unique professional player (ATP or WTA)
CREATE TABLE IF NOT EXISTS player (
    player_id     VARCHAR(100)  NOT NULL,
    full_name     VARCHAR(100),        -- TODO: full-name is not always present (null?)
    nationality   CHAR(3),                          -- IOC 3-letter country code
    birthdate     DATE,
    hand          CHAR(1) CHECK (hand IN ('R','L','U')),  -- R/L/Unknown
    height_cm     SMALLINT,
    CONSTRAINT pk_player PRIMARY KEY (player_id)
);

-- TOURNAMENT: a recurring named event (e.g. Wimbledon, US Open)
CREATE TABLE IF NOT EXISTS tournament (
    tourn_id      VARCHAR(100)  NOT NULL,            -- e.g. '540' = Wimbledon in csv data
    name          VARCHAR(100) NOT NULL,
    surface       VARCHAR(20)  CHECK (surface IN ('Hard','Clay','Grass','Carpet')),
    level         CHAR(2)      CHECK (level IN ('G','M','A','D','C','S','F','U')),
                                                     -- G=Grand Slam, M = Masters 1000 (ATP) / WTA 1000, A = ATP 500 / WTA 500, D = Davis Cup / Billie Jean King Cup
                                                     -- , C = Challenger (ATP minor league), S = Satellite / ITF, F = Tour Finals / season-ending championship, U = Unknown / other
    location      VARCHAR(100),
    CONSTRAINT pk_tournament PRIMARY KEY (tourn_id),
    INDEX idx_tournament_level USING HASH (level)
);

-- EDITION: one yearly instance of a tournament (weak entity)
CREATE TABLE IF NOT EXISTS edition (
    tourn_id      VARCHAR(100)  NOT NULL,
    year       INT NOT NULL,             
    prize_money   INT,                      -- USD; NULL for older editions
    draw_size     INT,
    CONSTRAINT pk_edition    PRIMARY KEY (tourn_id, year),
    CONSTRAINT fk_edition_t  FOREIGN KEY (tourn_id) REFERENCES tournament(tourn_id)
);

-- DOUBLES_TEAM: a named partnership between two players
CREATE TABLE IF NOT EXISTS doubles_team (
    team_id       INT NOT NULL AUTO_INCREMENT,
    player1_id    VARCHAR(100)   NOT NULL,
    player2_id    VARCHAR(100)   NOT NULL,
    start_date    DATE,
    end_date      DATE,
    CONSTRAINT pk_doubles_team   PRIMARY KEY (team_id),
    CONSTRAINT fk_dt_player1     FOREIGN KEY (player1_id) REFERENCES player(player_id),
    CONSTRAINT fk_dt_player2     FOREIGN KEY (player2_id) REFERENCES player(player_id),
    CONSTRAINT chk_players_diff  CHECK (player1_id <> player2_id)
);

-- MATCH: a single contest (singles or doubles) in an edition
CREATE TABLE IF NOT EXISTS `match` (
    match_id      VARCHAR(100)  NOT NULL,             -- e.g. '2023-520-001'
    tourn_id      VARCHAR(100)  NOT NULL,
    edition_year  INT     NOT NULL,
    score         VARCHAR(60),                       -- for exampPLe: '6-3 7-5 6-2'; NULL if walkover/Forfeit
    round         VARCHAR(15),                       -- for exampPLe:  'F','SF','QF','R32', etc.
    match_date    DATE,
    match_type    ENUM('singles','doubles') NOT NULL DEFAULT 'singles',
    num_sets TINYINT,   -- pre-computed from score string, NULL for retirements
    CONSTRAINT pk_match    PRIMARY KEY (match_id),
    CONSTRAINT fk_match_ed FOREIGN KEY (tourn_id, edition_year) REFERENCES edition(tourn_id, year),
    INDEX idx_match_type_date USING BTREE (match_type, match_date) -- for query optimization
);

-- MATCH_PLAYER: participation + per-player stats for each match (singles)
CREATE TABLE IF NOT EXISTS match_player (
    match_id      VARCHAR(100)  NOT NULL,
    player_id     VARCHAR(100)  NOT NULL,
    result        CHAR(1)      NOT NULL CHECK (result IN ('W','L')),
    seed          INT,
    -- statistics (NULL if unavailable)
    aces          SMALLINT,
    double_faults SMALLINT,
    serve_pts     SMALLINT,                 -- total serve points played
    first_in      SMALLINT,                 -- 1st serves in (can calculate percentage later)
    first_won     SMALLINT,                 -- 1st serve points won
    second_won    SMALLINT,                 -- 2nd serve points won
    bp_saved      SMALLINT,
    bp_faced      SMALLINT,
    CONSTRAINT pk_match_player  PRIMARY KEY (match_id, player_id),
    CONSTRAINT fk_mp_match      FOREIGN KEY (match_id)  REFERENCES `match`(match_id),
    CONSTRAINT fk_mp_player     FOREIGN KEY (player_id) REFERENCES player(player_id),
    INDEX idx_mp_player USING HASH (player_id) -- for query optimization
);

-- TEAM_MATCH: participation of a doubles team in a doubles match
CREATE TABLE IF NOT EXISTS team_match (
    match_id      VARCHAR(100)  NOT NULL,
    team_id       INT NOT NULL,
    result        CHAR(1)      NOT NULL CHECK (result IN ('W','L')),
    CONSTRAINT pk_team_match  PRIMARY KEY (match_id, team_id),
    CONSTRAINT fk_tm_match    FOREIGN KEY (match_id) REFERENCES `match`(match_id),
    CONSTRAINT fk_tm_team     FOREIGN KEY (team_id)  REFERENCES doubles_team(team_id)
);

-- RANKING: weekly singles ranking snapshots per player
CREATE TABLE IF NOT EXISTS ranking (
    player_id     VARCHAR(100)  NOT NULL,
    rank_date     DATE NOT NULL,
    rank_pos      SMALLINT  NOT NULL,
    rank_pts      INT,                      -- NULL for pre-1985 entries
    CONSTRAINT pk_ranking    PRIMARY KEY (player_id, rank_date),
    CONSTRAINT fk_rank_player FOREIGN KEY (player_id) REFERENCES player(player_id),
    INDEX idx_rank_player (player_id, rank_date) -- for query optimization
);

-- DBL_RANKING: weekly doubles ranking snapshots per team
CREATE TABLE IF NOT EXISTS dbl_ranking (
    team_id       INT NOT NULL,
    rank_date     DATE NOT NULL,
    rank_pos      SMALLINT NOT NULL,
    rank_pts      INT ,                      -- NULL for older entries
    CONSTRAINT pk_dbl_ranking  PRIMARY KEY (team_id, rank_date),
    CONSTRAINT fk_dr_team      FOREIGN KEY (team_id) REFERENCES doubles_team(team_id)
);