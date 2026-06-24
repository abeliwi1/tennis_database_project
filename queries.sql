-- Q1 
-- ENGLISH: What is the average number of aces per match for a
-- given player (let's say Roger Federer) on each surface type (Hard, Clay, Grass)?

SELECT
    t.surface,
    COUNT(*)                   AS matches_with_stats,
    ROUND(AVG(mp.aces), 2)     AS avg_aces,
    MAX(mp.aces)               AS max_aces
FROM match_player mp
JOIN `match`    m ON mp.match_id  = m.match_id
JOIN tournament t ON m.tourn_id   = t.tourn_id
JOIN player     p ON mp.player_id = p.player_id
WHERE p.full_name  = 'Roger Federer'
  AND mp.aces IS NOT NULL
  AND t.surface  IS NOT NULL
  AND m.match_type = 'singles'
GROUP BY t.surface
ORDER BY avg_aces DESC;


-- Q2 
-- ENGLISH: Which players have won at least 3 different Grand
-- Slam titles, and what is their total title count and Grand
-- Slam finals win percentage?

WITH gs_finals AS (
    SELECT mp.player_id, t.tourn_id,
           SUM(CASE WHEN mp.result = 'W' THEN 1 ELSE 0 END)  AS titles,
           COUNT(*)                                           AS finals_played
    FROM match_player mp
    JOIN `match`    m ON mp.match_id = m.match_id
    JOIN tournament t ON m.tourn_id  = t.tourn_id
    WHERE t.level = 'G' AND m.round = 'F' AND m.match_type = 'singles'
    GROUP BY mp.player_id, t.tourn_id
),
multi_slam AS (
    SELECT player_id FROM gs_finals WHERE titles > 0
    GROUP BY player_id HAVING COUNT(DISTINCT tourn_id) >= 3
)
SELECT p.full_name,
       SUM(gf.titles)                                                        AS total_gs_titles,
       SUM(gf.finals_played)                                                 AS total_gs_finals,
       ROUND(100.0*SUM(gf.titles)/NULLIF(SUM(gf.finals_played),0), 1)       AS finals_win_pct
FROM multi_slam ms
JOIN gs_finals gf ON ms.player_id = gf.player_id
JOIN player    p  ON ms.player_id = p.player_id
GROUP BY p.player_id, p.full_name
ORDER BY total_gs_titles DESC;


-- Q3 
-- ENGLISH: For a specified player, what is their head-to-head
-- record against opponents who were ranked in the top 10 at the
-- time of the match?

-- EXPLAIN ()
SELECT
    p.full_name                                                   AS player,
    COUNT(*)                                                      AS matches_vs_top10,
    SUM(CASE WHEN mp.result = 'W' THEN 1 ELSE 0 END)              AS wins,
    SUM(CASE WHEN mp.result = 'L' THEN 1 ELSE 0 END)              AS losses,
    ROUND(100.0*SUM(CASE WHEN mp.result='W' THEN 1 ELSE 0 END)
          /NULLIF(COUNT(*),0), 1)                                 AS win_pct
FROM match_player mp
JOIN player       p   ON mp.player_id  = p.player_id
JOIN `match`      m   ON mp.match_id   = m.match_id
JOIN match_player opp ON opp.match_id  = m.match_id
                     AND opp.player_id <> mp.player_id
JOIN ranking      r   ON r.player_id   = opp.player_id
                     AND r.rank_date   = (
                         SELECT MAX(r2.rank_date) FROM ranking r2
                         WHERE r2.player_id  = opp.player_id
                           AND r2.rank_date <= m.match_date)
WHERE p.full_name  = 'Roger Federer'
  AND r.rank_pos  <= 10
  AND m.match_type = 'singles';


-- Q4 
-- ENGLISH: Which players have the highest career break-point
-- conversion rate in Grand Slam finals (min 3 appearances,
-- stats era only)?

SELECT
    p.full_name,
    COUNT(DISTINCT m.match_id)                                     AS gs_finals,
    SUM(opp.bp_faced)                                              AS opp_bp_faced,
    SUM(opp.bp_faced - opp.bp_saved)                               AS breaks_made,
    ROUND(100.0*SUM(opp.bp_faced-opp.bp_saved)
          /NULLIF(SUM(opp.bp_faced),0), 1)                        AS bp_conv_pct
FROM match_player mp
JOIN player       p   ON mp.player_id  = p.player_id
JOIN `match`      m   ON mp.match_id   = m.match_id
JOIN tournament   t   ON m.tourn_id    = t.tourn_id
JOIN match_player opp ON opp.match_id  = m.match_id
                     AND opp.player_id <> mp.player_id
WHERE t.level = 'G' AND m.round = 'F' AND m.match_type = 'singles'
  AND opp.bp_faced IS NOT NULL AND opp.bp_faced > 0
GROUP BY p.player_id, p.full_name
HAVING gs_finals >= 3
ORDER BY bp_conv_pct DESC LIMIT 20;


-- Q5 
-- ENGLISH: How does a specified player's first-serve percentage
-- change between rounds of Grand Slams?

SELECT
    m.round,
    COUNT(*)                                                              AS matches,
    ROUND(100.0*SUM(mp.first_in)/NULLIF(SUM(mp.serve_pts),0), 1)         AS first_srv_pct,
    ROUND(100.0*SUM(mp.first_won)/NULLIF(SUM(mp.first_in),0), 1)         AS won_on_1st_pct,
    ROUND(100.0*SUM(mp.second_won)
          /NULLIF(SUM(mp.serve_pts)-SUM(mp.first_in),0), 1)              AS won_on_2nd_pct
FROM match_player mp
JOIN player     p ON mp.player_id = p.player_id
JOIN `match`    m ON mp.match_id  = m.match_id
JOIN tournament t ON m.tourn_id   = t.tourn_id
WHERE p.full_name  = 'Roger Federer'
  AND t.level      = 'G'
  AND m.match_type = 'singles'
  AND mp.serve_pts IS NOT NULL
  AND m.round IN ('R128','R64','R32','R16','QF','SF','F')
GROUP BY m.round
ORDER BY FIELD(m.round,'R128','R64','R32','R16','QF','SF','F');


-- Q6 
-- ENGLISH: List all Grand Slam editions where the champion was
-- ranked outside the top 5 at the time of the tournament.

SELECT
    t.name        AS grand_slam,
    m.edition_year     AS year,
    p.full_name   AS champion,
    r.rank_pos    AS ranking_at_time
FROM match_player mp
JOIN player       p  ON mp.player_id = p.player_id
JOIN `match`      m  ON mp.match_id  = m.match_id
JOIN tournament   t  ON m.tourn_id   = t.tourn_id
JOIN ranking      r  ON r.player_id  = mp.player_id
                    AND r.rank_date  = (
                        SELECT MAX(r2.rank_date) FROM ranking r2
                        WHERE r2.player_id  = mp.player_id
                          AND r2.rank_date <= m.match_date)
WHERE t.level = 'G' AND m.round = 'F'
  AND m.match_type = 'singles' AND mp.result = 'W'
  AND r.rank_pos > 5
ORDER BY r.rank_pos DESC, m.edition_year DESC;


-- Q7 
-- ENGLISH: What is the average number of sets per match per
-- round at Wimbledon, grouped by decade since 1970?
-- (Set count approximated: spaces in score string + 1.)

SELECT
    CONCAT(FLOOR(m.edition_year/10)*10,'s')  AS decade,
    m.round,
    COUNT(*)                            AS matches,
    ROUND(AVG(num_sets), 2)             AS avg_sets
FROM `match` m
JOIN tournament t ON m.tourn_id = t.tourn_id
WHERE t.name LIKE '%Wimbledon%'
  AND m.match_type = 'singles'
  AND m.edition_year   >= 1970
  AND m.round IN ('R128','R64','R32','R16','QF','SF','F')
GROUP BY decade, m.round
ORDER BY decade, FIELD(m.round,'R128','R64','R32','R16','QF','SF','F');


-- Q8 
-- ENGLISH: Which tournament edition had the highest average
-- number of aces per match (min 10 matches with stats)?

SELECT
    t.name                  AS tournament,
    m.edition_year               AS year,
    t.surface,
    COUNT(*)                AS matches_with_stats,
    ROUND(AVG(mp.aces), 2)  AS avg_aces
FROM match_player mp
JOIN `match`    m ON mp.match_id = m.match_id
JOIN tournament t ON m.tourn_id  = t.tourn_id
WHERE mp.aces IS NOT NULL AND m.match_type = 'singles'
GROUP BY t.tourn_id, m.edition_year
HAVING matches_with_stats >= 10
ORDER BY avg_aces DESC LIMIT 20;


-- Q9 
-- ENGLISH: For each Grand Slam surface type, compute the mean
-- number of sets per match across all years.

SELECT
    t.surface,
    t.name                                      AS grand_slam,
    m.edition_year AS year,
    COUNT(*)                                    AS total_matches,
    ROUND(AVG(num_sets), 3)             AS avg_sets
FROM `match` m
JOIN tournament t USING(tourn_id)
WHERE t.level = 'G' AND m.match_type = 'singles'
GROUP BY t.tourn_id, t.surface
ORDER BY avg_sets DESC;


-- Q10 
-- ENGLISH: Which players have reached the final of all four
-- Grand Slams in the same calendar year (Calendar Slam attempt)?

-- EXPLAIN ()
SELECT
    p.full_name,
    m.edition_year                                             AS year,
    COUNT(DISTINCT t.tourn_id)                            AS slams_in_final,
    SUM(CASE WHEN mp.result = 'W' THEN 1 ELSE 0 END)      AS slams_won
FROM match_player mp
JOIN player     p ON mp.player_id = p.player_id
JOIN `match`    m ON mp.match_id  = m.match_id
JOIN tournament t ON m.tourn_id   = t.tourn_id
WHERE t.level = 'G' AND m.round = 'F' AND m.match_type = 'singles'
GROUP BY p.player_id, p.full_name, m.edition_year
HAVING slams_in_final = 4
ORDER BY m.edition_year DESC, slams_won DESC;


-- Q11 
-- ENGLISH: Compute the mean ATP/WTA ranking of Grand Slam
-- champions grouped by decade (1970s, 1980s, etc.).

SELECT
    CONCAT(FLOOR(m.edition_year/10)*10,'s')  AS decade,
    COUNT(*)                            AS champions,
    ROUND(AVG(r.rank_pos), 1)           AS avg_champion_rank,
    MIN(r.rank_pos)                     AS best_rank,
    MAX(r.rank_pos)                     AS worst_rank
FROM match_player mp
JOIN `match`    m  ON mp.match_id  = m.match_id
JOIN tournament t  ON m.tourn_id   = t.tourn_id
JOIN ranking    r  ON r.player_id  = mp.player_id
                  AND r.rank_date  = (
                      SELECT MAX(r2.rank_date) FROM ranking r2
                      WHERE r2.player_id  = mp.player_id
                        AND r2.rank_date <= m.match_date)
WHERE t.level = 'G' AND m.round = 'F'
  AND m.match_type = 'singles' AND mp.result = 'W'
GROUP BY decade ORDER BY decade;


-- Q12 
-- ENGLISH: Which players spent the most total weeks ranked
-- world No. 1?

SELECT
    p.full_name,
    COUNT(*) AS weeks_at_no1
FROM ranking r
JOIN player  p ON r.player_id = p.player_id
WHERE r.rank_pos = 1
GROUP BY p.player_id, p.full_name
ORDER BY weeks_at_no1 DESC LIMIT 20;


-- Q13 
-- ENGLISH: List the top 10 players by total Grand Slam match
-- wins, broken down by surface.

SELECT
    p.full_name,
    t.surface,
    COUNT(*) AS gs_match_wins
FROM match_player mp
JOIN player     p ON mp.player_id = p.player_id
JOIN `match`    m ON mp.match_id  = m.match_id
JOIN tournament t ON m.tourn_id   = t.tourn_id
WHERE t.level = 'G' AND m.match_type = 'singles' AND mp.result = 'W'
  AND p.player_id IN (
      SELECT player_id FROM (
          SELECT mp2.player_id, COUNT(*) AS w
          FROM match_player mp2
          JOIN `match`    m2 ON mp2.match_id = m2.match_id
          JOIN tournament t2 ON m2.tourn_id  = t2.tourn_id
          WHERE t2.level = 'G' AND m2.match_type = 'singles' AND mp2.result = 'W'
          GROUP BY mp2.player_id ORDER BY w DESC LIMIT 10
      ) top10
  )
GROUP BY p.player_id, p.full_name, t.surface
ORDER BY t.surface, gs_match_wins;


-- Q14 
-- ENGLISH: For each nationality, compute the total number of
-- Grand Slam singles titles won by players of that country.

SELECT
    p.nationality,
    COUNT(*) AS gs_titles
FROM match_player mp
JOIN player     p ON mp.player_id = p.player_id
JOIN `match`    m ON mp.match_id  = m.match_id
JOIN tournament t ON m.tourn_id   = t.tourn_id
WHERE t.level = 'G' AND m.round = 'F'
  AND m.match_type = 'singles' AND mp.result = 'W'
  AND p.nationality IS NOT NULL
GROUP BY p.nationality
ORDER BY gs_titles DESC LIMIT 20;


-- Q15 
-- ENGLISH: Which doubles pairs have the best win percentage in
-- Grand Slam finals with at least 3 appearances?

SELECT
    p1.full_name                                                AS player_1,
    p2.full_name                                                AS player_2,
    COUNT(*)                                                    AS finals_played,
    SUM(CASE WHEN tm.result = 'W' THEN 1 ELSE 0 END)            AS titles,
    ROUND(100.0*SUM(CASE WHEN tm.result='W' THEN 1 ELSE 0 END)
          /NULLIF(COUNT(*),0), 1)                               AS win_pct
FROM team_match   tm
JOIN doubles_team dt ON tm.team_id    = dt.team_id
JOIN player       p1 ON dt.player1_id = p1.player_id
JOIN player       p2 ON dt.player2_id = p2.player_id
JOIN `match`      m  ON tm.match_id   = m.match_id
JOIN tournament   t  ON m.tourn_id    = t.tourn_id
WHERE t.level = 'G' AND m.round = 'F'
GROUP BY dt.team_id, p1.full_name, p2.full_name
HAVING finals_played >= 3
ORDER BY win_pct DESC, titles DESC;