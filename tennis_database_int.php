<?php
// Database Interface
require_once('mysql.inc.php');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', 1); // this is for error checking
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$QUERIES = [
    1  => ['title'=>'Aces by Surface',            'cat'=>'Player Performance',  'desc'=>'Average aces per match for a player on each surface.',          'player'=>true,  'chart'=>'bar'],
    2  => ['title'=>'Top Multi-Slam Champions',        'cat'=>'Player Performance',  'desc'=>'Players who have won 3+ different Grand Slam titles.',           'player'=>false, 'chart'=>'bar'],
    3  => ['title'=>'Head-to-Head vs Top 10',      'cat'=>'Player Performance',  'desc'=>"Win-loss record against top-10-ranked opponents.",              'player'=>true,  'chart'=>'none'],
    4  => ['title'=>'Break Point Conversion in GS Finals',  'cat'=>'Player Performance',  'desc'=>'Highest break-point conversion rate in Grand Slam finals.',     'player'=>false, 'chart'=>'bar'],
    5  => ['title'=>'1st Serve % by Round',        'cat'=>'Player Performance',  'desc'=>"First-serve stats across Grand Slam rounds for a player.",      'player'=>true,  'chart'=>'line'],
    6  => ['title'=>'Upset GS Champions',          'cat'=>'Tournament & Edition','desc'=>'Grand Slam editions won by a player ranked outside top 5.',     'player'=>false, 'chart'=>'none'],
    7  => ['title'=>'Wimbledon Sets by Decade',    'cat'=>'Tournament & Edition','desc'=>'Avg sets per match per round at Wimbledon by decade.',          'player'=>false, 'chart'=>'none'],
    8  => ['title'=>'Highest-Ace Editions',        'cat'=>'Tournament & Edition','desc'=>'Tournament editions with the highest avg aces per match.',      'player'=>false, 'chart'=>'bar'],
    9  => ['title'=>'Sets per GS Surface',         'cat'=>'Tournament & Edition','desc'=>'Mean sets per match for each Grand Slam by surface.',           'player'=>false, 'chart'=>'bar'],
    10 => ['title'=>'Calendar Slam Attempts',      'cat'=>'Tournament & Edition','desc'=>'Players who reached the final of all 4 Grand Slams in one year.','player'=>false,'chart'=>'none'],
    11 => ['title'=>'Champion Rank by Decade',     'cat'=>'Rankings & Trends',   'desc'=>'Mean world ranking of Grand Slam champions per decade.',        'player'=>false, 'chart'=>'bar'],
    12 => ['title'=>'Most Weeks at No. 1',         'cat'=>'Rankings & Trends',   'desc'=>'Players with the most total weeks ranked world No. 1.',         'player'=>false, 'chart'=>'bar'],
    13 => ['title'=>'GS Wins by Surface',          'cat'=>'Rankings & Trends',   'desc'=>'Top 10 players by Grand Slam match wins, by surface.',          'player'=>false, 'chart'=>'none'],
    14 => ['title'=>'GS Titles by Nationality',    'cat'=>'Rankings & Trends',   'desc'=>'Total Grand Slam singles titles won per country.',              'player'=>false, 'chart'=>'bar'],
    15 => ['title'=>'Best Doubles Pairs',          'cat'=>'Doubles',             'desc'=>'Doubles pairs with the best win % in Grand Slam finals (3+ finals played).','player'=>false,'chart'=>'none'],
];

$q      = isset($_GET['q'])      ? (int)$_GET['q']                  : 0;
$player = isset($_GET['player']) ? trim(strip_tags($_GET['player'])) : 'Roger Federer'; // default player (I love him lol)
$error  = '';
$rows   = [];
$cols   = [];

if ($q >= 1 && $q <= 15) {
    try {
        // $db = get_db();
        $stmt = null; // set with queery switch
        switch ($q) {
        case 1:
            $stmt = $db->prepare("SELECT t.surface, COUNT(*) AS matches,
                                    ROUND(AVG(mp.aces), 2) AS avg_aces, MAX(mp.aces) AS max_aces 
                                    FROM match_player mp 
                                    JOIN `match` m ON mp.match_id=m.match_id 
                                    JOIN tournament t ON m.tourn_id=t.tourn_id 
                                    JOIN player p ON mp.player_id=p.player_id 
                                    WHERE p.full_name=? 
                                        AND mp.aces IS NOT NULL 
                                        AND t.surface IS NOT NULL 
                                        AND m.match_type='singles' 
                                    GROUP BY t.surface 
                                    ORDER BY avg_aces DESC
                                ");
            $stmt->bind_param("s", $player);
            $stmt->execute(); break;
        case 2:
            $stmt = $db->prepare("WITH gs_finals AS (SELECT mp.player_id,t.tourn_id,SUM(CASE WHEN mp.result='W' THEN 1 ELSE 0 END) AS titles,COUNT(*) AS finals_played FROM match_player mp JOIN `match` m ON mp.match_id=m.match_id JOIN tournament t ON m.tourn_id=t.tourn_id WHERE t.level='G' AND m.round='F' AND m.match_type='singles' GROUP BY mp.player_id,t.tourn_id),multi AS(SELECT player_id FROM gs_finals WHERE titles>0 GROUP BY player_id HAVING COUNT(DISTINCT tourn_id)>=3) SELECT p.full_name,SUM(gf.titles) AS total_gs_titles,SUM(gf.finals_played) AS total_gs_finals,ROUND(100.0*SUM(gf.titles)/NULLIF(SUM(gf.finals_played),0),1) AS finals_win_pct FROM multi ms JOIN gs_finals gf ON ms.player_id=gf.player_id JOIN player p ON ms.player_id=p.player_id GROUP BY p.player_id,p.full_name ORDER BY total_gs_titles DESC");
            $stmt->execute(); break;
        case 3:
            $stmt = $db->prepare("SELECT p.full_name AS player,COUNT(*) AS matches_vs_top10,SUM(CASE WHEN mp.result='W' THEN 1 ELSE 0 END) AS wins,SUM(CASE WHEN mp.result='L' THEN 1 ELSE 0 END) AS losses,ROUND(100.0*SUM(CASE WHEN mp.result='W' THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0),1) AS win_pct FROM match_player mp JOIN player p ON mp.player_id=p.player_id JOIN `match` m ON mp.match_id=m.match_id JOIN match_player opp ON opp.match_id=m.match_id AND opp.player_id<>mp.player_id JOIN ranking r ON r.player_id=opp.player_id AND r.rank_date=(SELECT MAX(r2.rank_date) FROM ranking r2 WHERE r2.player_id=opp.player_id AND r2.rank_date<=m.match_date) WHERE p.full_name=? AND r.rank_pos<=10 AND m.match_type='singles'");
            $stmt->bind_param("s", $player);
            $stmt->execute(); break;
        case 4:
            $stmt = $db->prepare("SELECT p.full_name,COUNT(DISTINCT m.match_id) AS gs_finals,SUM(opp.bp_faced) AS opp_bp_faced,SUM(opp.bp_faced-opp.bp_saved) AS breaks_made,ROUND(100.0*SUM(opp.bp_faced-opp.bp_saved)/NULLIF(SUM(opp.bp_faced),0),1) AS bp_conv_pct FROM match_player mp JOIN player p ON mp.player_id=p.player_id JOIN `match` m ON mp.match_id=m.match_id JOIN tournament t ON m.tourn_id=t.tourn_id JOIN match_player opp ON opp.match_id=m.match_id AND opp.player_id<>mp.player_id WHERE t.level='G' AND m.round='F' AND m.match_type='singles' AND opp.bp_faced IS NOT NULL AND opp.bp_faced>0 GROUP BY p.player_id,p.full_name HAVING gs_finals>=3 ORDER BY bp_conv_pct DESC LIMIT 20");
            $stmt->execute(); break;
        case 5:
            $stmt = $db->prepare("SELECT m.round,COUNT(*) AS matches,ROUND(100.0*SUM(mp.first_in)/NULLIF(SUM(mp.serve_pts),0),1) AS first_srv_pct,ROUND(100.0*SUM(mp.first_won)/NULLIF(SUM(mp.first_in),0),1) AS won_on_1st_pct,ROUND(100.0*SUM(mp.second_won)/NULLIF(SUM(mp.serve_pts)-SUM(mp.first_in),0),1) AS won_on_2nd_pct FROM match_player mp JOIN player p ON mp.player_id=p.player_id JOIN `match` m ON mp.match_id=m.match_id JOIN tournament t ON m.tourn_id=t.tourn_id WHERE p.full_name=? AND t.level='G' AND m.match_type='singles' AND mp.serve_pts IS NOT NULL AND m.round IN ('R128','R64','R32','R16','QF','SF','F') GROUP BY m.round ORDER BY FIELD(m.round,'R128','R64','R32','R16','QF','SF','F')");
            $stmt->bind_param("s", $player);
            $stmt->execute(); break;
        case 6:
            $stmt = $db->prepare("SELECT t.name AS grand_slam,m.edition_year AS year,p.full_name AS champion,r.rank_pos AS ranking_at_time FROM match_player mp JOIN player p ON mp.player_id=p.player_id JOIN `match` m ON mp.match_id=m.match_id JOIN tournament t ON m.tourn_id=t.tourn_id JOIN ranking r ON r.player_id=mp.player_id AND r.rank_date=(SELECT MAX(r2.rank_date) FROM ranking r2 WHERE r2.player_id=mp.player_id AND r2.rank_date<=m.match_date) WHERE t.level='G' AND m.round='F' AND m.match_type='singles' AND mp.result='W' AND r.rank_pos>5 ORDER BY r.rank_pos DESC,m.edition_year DESC");
            $stmt->execute();
            break;
        case 7:
            $stmt = $db->prepare("SELECT CONCAT(FLOOR(m.edition_year/10)*10,'s') AS decade,m.round,COUNT(*) AS matches,ROUND(AVG(num_sets),2) AS avg_sets FROM `match` m JOIN tournament t ON m.tourn_id=t.tourn_id WHERE t.name LIKE '%Wimbledon%' AND m.match_type='singles' AND m.edition_year>=1970 AND m.round IN ('R128','R64','R32','R16','QF','SF','F') GROUP BY decade,m.round ORDER BY decade,FIELD(m.round,'R128','R64','R32','R16','QF','SF','F')");
            $stmt->execute();
            break;
        case 8:
            $stmt = $db->prepare("SELECT CONCAT(t.name,' ',m.edition_year) AS edition, t.surface,
                                    COUNT(*) AS matches_with_stats,
                                    ROUND(AVG(mp.aces), 2) AS avg_aces
                                 FROM match_player mp 
                                 JOIN `match` m ON mp.match_id=m.match_id 
                                 JOIN tournament t ON m.tourn_id=t.tourn_id 
                                 WHERE mp.aces IS NOT NULL 
                                    AND m.match_type='singles' 
                                GROUP BY t.tourn_id,m.edition_year 
                                HAVING matches_with_stats>=10 
                                ORDER BY avg_aces DESC LIMIT 20
                                ");
            $stmt->execute();
            break;
        case 9:
            $stmt = $db->prepare("SELECT t.surface,
                                    COUNT(*) AS total_matches,
                                    ROUND(AVG(num_sets), 3) AS avg_sets
                                  FROM `match` m
                                  JOIN tournament t ON m.tourn_id = t.tourn_id
                                  WHERE t.level = 'G'
                                    AND m.match_type = 'singles'
                                  GROUP BY t.surface
                                  ORDER BY avg_sets DESC
                                  ");
            $stmt->execute();
            break;
        case 10:
            $stmt = $db->prepare("SELECT p.full_name,m.edition_year AS year,COUNT(DISTINCT t.tourn_id) AS slams_in_final,SUM(CASE WHEN mp.result='W' THEN 1 ELSE 0 END) AS slams_won FROM match_player mp JOIN player p ON mp.player_id=p.player_id JOIN `match` m ON mp.match_id=m.match_id JOIN tournament t ON m.tourn_id=t.tourn_id WHERE t.level='G' AND m.round='F' AND m.match_type='singles' GROUP BY p.player_id,p.full_name,m.edition_year HAVING slams_in_final=4 ORDER BY m.edition_year DESC,slams_won DESC");
            $stmt->execute();
            break;
        case 11:
            $stmt = $db->prepare("SELECT CONCAT(FLOOR(m.edition_year/10)*10,'s') AS decade,COUNT(*) AS champions,ROUND(AVG(r.rank_pos),1) AS avg_champion_rank,MIN(r.rank_pos) AS best_rank,MAX(r.rank_pos) AS worst_rank FROM match_player mp JOIN `match` m ON mp.match_id=m.match_id JOIN tournament t ON m.tourn_id=t.tourn_id JOIN ranking r ON r.player_id=mp.player_id AND r.rank_date=(SELECT MAX(r2.rank_date) FROM ranking r2 WHERE r2.player_id=mp.player_id AND r2.rank_date<=m.match_date) WHERE t.level='G' AND m.round='F' AND m.match_type='singles' AND mp.result='W' GROUP BY decade ORDER BY decade");
            $stmt->execute();
            break;
        case 12:
            $stmt = $db->prepare("SELECT p.full_name,COUNT(*) AS weeks_at_number_1 FROM ranking r JOIN player p ON r.player_id=p.player_id WHERE r.rank_pos=1 GROUP BY p.player_id,p.full_name ORDER BY weeks_at_number_1 DESC LIMIT 20");
            $stmt->execute();
            break;
        case 13:
            $stmt = $db->prepare("SELECT p.full_name,t.surface,COUNT(*) AS gs_match_wins FROM match_player mp JOIN player p ON mp.player_id=p.player_id JOIN `match` m ON mp.match_id=m.match_id JOIN tournament t ON m.tourn_id=t.tourn_id WHERE t.level='G' AND m.match_type='singles' AND mp.result='W' AND p.player_id IN (SELECT player_id FROM (SELECT mp2.player_id,COUNT(*) AS w FROM match_player mp2 JOIN `match` m2 ON mp2.match_id=m2.match_id JOIN tournament t2 ON m2.tourn_id=t2.tourn_id WHERE t2.level='G' AND m2.match_type='singles' AND mp2.result='W' GROUP BY mp2.player_id ORDER BY w DESC LIMIT 10) top10) GROUP BY p.player_id,p.full_name,t.surface ORDER BY t.surface, gs_match_wins DESC");
            $stmt->execute();
            break;
        case 14:
            $stmt = $db->prepare("SELECT p.nationality,COUNT(*) AS gs_titles FROM match_player mp JOIN player p ON mp.player_id=p.player_id JOIN `match` m ON mp.match_id=m.match_id JOIN tournament t ON m.tourn_id=t.tourn_id WHERE t.level='G' AND m.round='F' AND m.match_type='singles' AND mp.result='W' AND p.nationality IS NOT NULL GROUP BY p.nationality ORDER BY gs_titles DESC LIMIT 20");
            $stmt->execute();
            break;
        case 15:
            $stmt = $db->prepare("SELECT p1.full_name AS player_1,
                                    p2.full_name AS player_2,
                                    COUNT(*) AS finals_played,
                                    SUM(CASE WHEN tm.result='W' THEN 1 ELSE 0 END) AS titles,   
                                    ROUND(100.0*SUM(CASE WHEN tm.result='W' THEN 1 ELSE 0 END)
                                        / NULLIF(COUNT(*), 0),1) AS win_pct 
                                  FROM team_match tm 
                                  JOIN doubles_team dt ON tm.team_id=dt.team_id 
                                  JOIN player p1 ON dt.player1_id=p1.player_id 
                                  JOIN player p2 ON dt.player2_id=p2.player_id 
                                  JOIN `match` m ON tm.match_id=m.match_id 
                                  JOIN tournament t ON m.tourn_id=t.tourn_id 
                                  WHERE t.level='G' 
                                    AND m.round='F' 
                                  GROUP BY dt.team_id, p1.full_name, p2.full_name 
                                  HAVING finals_played>=3 
                                  ORDER BY win_pct DESC, titles DESC");
            $stmt->execute();
            break;
        }
        if ($stmt) {
            // 1. Get the result object from the statement
            $result = $stmt->get_result();
            
            if ($result) {
                // 2. Fetch the data as an associative array 
                $rows = $result->fetch_all(MYSQLI_ASSOC);
                // 3. Get column names from the first row
                $cols = $rows ? array_keys($rows[0]) : [];
            }
            $stmt->close();
        }
        // $db->close();
    } catch (PDOException $e) {
        $error = 'Database error: ' . htmlspecialchars($e->getMessage());
    }
}

// Chart prep
$chart_labels = []; $chart_values = [];
$chart_type = ($q > 0) ? ($QUERIES[$q]['chart'] ?? 'none') : 'none';
if ($rows && $chart_type !== 'none' && count($cols) >= 2) {
    $skip = ['matches','total_matches','finals_played','gs_finals','champions','matches_with_stats',
             'year','ed_year','edition_year','surface','decade','nationality', 'grand_slam'];
    $vcol = '';
    foreach ($cols as $c) { if ($c !== $cols[0] && !in_array($c,$skip)) { $vcol=$c; break; } }
    if ($vcol) { foreach ($rows as $r) { $chart_labels[]=$r[$cols[0]]; $chart_values[]=(float)($r[$vcol]??0); } }
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tennis Stats Analysis DB</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<!-- favicon for my website - learn in FSJS course -->
    <link rel="icon" type="image/png" href="Tennis_Ball.png" />
<style>
:root{--green: #1D6A3E;--navy: #1A5276;--bg: #F4F6F7;--white: #fff;--border: #D5D8DC;--text: #2C3E50;--muted: #7F8C8D}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:var(--bg);color:var(--text)}
header{background:var(--green);color:#fff;padding:16px 28px;display:flex;align-items:center;gap:14px}
header h1{font-size:1.3rem}
header span{font-size:.8rem;color:#AED6F1}
.layout{display:flex;min-height:calc(100vh - 58px)}
nav{width:252px;background:var(--white);border-right:1px solid var(--border);padding:12px 0;flex-shrink:0;overflow-y:auto}
.cat{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);padding:14px 18px 5px}
nav a{display:block;padding:8px 18px;font-size:.83rem;color:var(--text);text-decoration:none;border-left:3px solid transparent}
nav a:hover{background:#EBF5FB}
nav a.on{background:#EBF5FB;border-left-color:var(--green);color:var(--green);font-weight:600}
nav a .n{display:inline-block;width:24px;font-size:.7rem;color:var(--muted)}
main{flex:1;padding:26px 32px;overflow-x:auto}
.welcome{background:var(--white);border:1px solid var(--border);border-radius:8px;padding:28px;max-width:660px}
.welcome h2{font-size:1.1rem;color:var(--green);margin-bottom:8px}
.welcome p{font-size:.88rem;line-height:1.65;color:var(--muted);margin-top:10px}
.qhead{margin-bottom:18px}
.qhead h2{font-size:1.1rem;color:var(--green);margin-bottom:5px}
.qhead p{font-size:.86rem;color:var(--muted)}
.pform{background:var(--white);border:1px solid var(--border);border-radius:8px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.pform label{font-size:.86rem;font-weight:600;white-space:nowrap}
.pform input{padding:7px 11px;border:1px solid var(--border);border-radius:6px;font-size:.86rem;width:240px}
.pform button{padding:7px 16px;background:var(--green);color:#fff;border:none;border-radius:6px;font-size:.86rem;cursor:pointer}
.pform button:hover{background: #3adb55}
.err{background: #FDEDEC;border:1px solid #F1948A;border-radius:6px;padding:11px 15px;font-size:.86rem;color: #922B21;margin-bottom:16px}
.none{background:var(--white);border:1px solid var(--border);border-radius:8px;padding:26px;text-align:center;color:var(--muted);font-size:.88rem}
.tw{background:var(--white);border:1px solid var(--border);border-radius:8px;overflow:auto;margin-bottom:24px}
table{width:100%;border-collapse:collapse;font-size:.84rem}
thead tr{background:var(--green);color: #fff}
thead th{padding:10px 13px;text-align:left;white-space:nowrap}
tbody tr:nth-child(even){background: #F8F9FA}
tbody tr:hover{background: #EBF5FB}
tbody td{padding:8px 13px;border-bottom:1px solid var(--border)}
.rn{color:var(--muted);font-size:.76rem}
.cw{background:var(--white);border:1px solid var(--border);border-radius:8px;padding:18px 22px;margin-bottom:24px}
.cw h3{font-size:.87rem;color:var(--muted);margin-bottom:12px}
canvas{max-height:320px}
.hint{font-size:.78rem;color:var(--muted);margin-top:6px}
footer{text-align:center;padding:12px;font-size:.76rem;color:var(--muted);border-top:1px solid var(--border)}
</style></head><body>

<header>
  <div>
    <h1>🎾 Tennis Statistics Database</h1>
  </div>
</header>

<div class="layout">
<nav>
<?php
$cats = [];
foreach ($QUERIES as $i => $meta) $cats[$meta['cat']][] = $i;
foreach ($cats as $cat => $ids):
  echo '<div class="cat">'.htmlspecialchars($cat).'</div>';
  foreach ($ids as $i):
    $pl = $QUERIES[$i]['player'] ? '&player='.urlencode($player) : '';
    $on = $q===$i ? ' on' : '';
    echo "<a href=\"?q=$i$pl\" class=\"$on\"><span class=\"n\">Q$i</span>".htmlspecialchars($QUERIES[$i]['title'])."</a>";
  endforeach;
endforeach;
?>
</nav>

<main>
<?php if (!$q): ?>
<div class="welcome">
  <h2>Welcome to the Tennis Statistics Database</h2>
  <p>Explore historical ATP and WTA match data from the Open Era (1968–present), covering singles and doubles across Grand Slams, Masters events, and more.</p>
  <p>Select a query from the sidebar. </p>
  <p>Player-specific queries (Q1, Q3, Q5) default to <strong>Roger Federer</strong> — type any player name to change.</p>
  <p style="font-size:.8rem;margin-top:16px">Data Used: Jeff Sackmann's tennis_atp / tennis_wta repositories</p>
</div>

<?php else:
  $meta = $QUERIES[$q];
  echo '<div class="qhead"><h2>Q'.$q.' — '.htmlspecialchars($meta['title']).'</h2><p>'.htmlspecialchars($meta['desc']).'</p></div>';

  if ($meta['player']):
?>
    <form class="pform" method="get">
    <input type="hidden" name="q" value="<?= $q ?>">
    <input type="hidden" name="player" id="player_id_input"
           value="<?= htmlspecialchars($player) ?>">
    <label>Player:</label>
    <div style="position:relative; flex:1; min-width:240px;">
        <input type="text"
               id="player_search_box"
               autocomplete="off"
               placeholder="e.g. Novak Djokovic"
               value="<?= htmlspecialchars($player) ?>"
               style="width:100%">
        <ul id="player_dropdown" style="
            display:none; position:absolute; top:100%; left:0; right:0;
            background:white; border:1px solid var(--border); border-top:none;
            border-radius:0 0 6px 6px; list-style:none; margin:0; padding:0;
            z-index:999; max-height:220px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,.1);
        "></ul>
    </div>
    <button type="submit">Search</button>
</form>

<script>
const box      = document.getElementById('player_search_box');
const dropdown = document.getElementById('player_dropdown');
const hidden   = document.getElementById('player_id_input');
let debounce;

box.addEventListener('input', () => {
    clearTimeout(debounce);
    const term = box.value.trim();
    if (term.length < 2) { dropdown.style.display = 'none'; return; }

    debounce = setTimeout(() => {
        fetch('player_search.php?term=' + encodeURIComponent(term))
            .then(r => r.json())
            .then(players => {
                dropdown.innerHTML = '';
                if (!players.length) { dropdown.style.display = 'none'; return; }
                players.forEach(p => {
                    const li = document.createElement('li');
                    li.textContent = p.name;
                    li.style.cssText = 'padding:8px 13px; cursor:pointer; font-size:.85rem;';
                    li.addEventListener('mouseenter', () => li.style.background = '#EBF5FB');
                    li.addEventListener('mouseleave', () => li.style.background = '');
                    li.addEventListener('mousedown', () => {
                        box.value    = p.name;
                        hidden.value = p.name;  // pass name to PHP for query
                        dropdown.style.display = 'none';
                    });
                    dropdown.appendChild(li);
                });
                dropdown.style.display = 'block';
            });
    }, 250);  // 250ms debounce — waits for user to stop typing
});

// close dropdown when clicking elsewhere
document.addEventListener('click', e => {
    if (!box.contains(e.target) && !dropdown.contains(e.target))
        dropdown.style.display = 'none';
});

document.querySelector('.pform').addEventListener('submit', () => {
    // if user typed a name without selecting from dropdown,
    // sync the hidden input with whatever is in the text box
    hidden.value = box.value.trim();
});
</script>
<?php endif;

  if ($error) echo '<div class="err">'.$error.'</div>';
  elseif (!$rows) echo '<div class="none">No results found'.($meta['player']?' for <strong>'.htmlspecialchars($player).'</strong>':'').'. Stats may be unavailable for this player/era.</div>';
  else
    // Chart // TODO: question 9 chart is off (FIX!)
    if ($chart_type !== 'none' && count($chart_labels)):
      $vlabel = str_replace('_',' ',ucwords($cols[1]??'','_'));
?>
    <div class="cw"><h3><?=htmlspecialchars($meta['title'])?> — <?=htmlspecialchars($vlabel)?></h3>
    <canvas id="ch"></canvas></div>
<?php endif; ?>
    <div class="tw"><table>
      <thead><tr><th>#</th><?php foreach($cols as $c) echo '<th>'.htmlspecialchars(str_replace('_',' ',ucwords($c,'_'))).'</th>'; ?></tr></thead>
      <tbody><?php foreach($rows as $i=>$r): ?>
        <tr><td class="rn"><?=$i+1?></td><?php foreach($r as $v) echo '<td>'.htmlspecialchars((string)($v??'—')).'</td>'; ?></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <p class="hint"><?=count($rows)?> row<?=count($rows)!==1?'s':''?> returned.</p>
<?php endif; ?>
</main></div>

<footer>Tennis Statistics DB &nbsp;|&nbsp; 601.315 JHU &nbsp;|&nbsp; Data Used: Jeff Sackmann (tennis_atp / tennis_wta)</footer>

<?php if ($chart_type!=='none' && count($chart_labels)>0): ?>
<script>
new Chart(document.getElementById('ch'),{
  type:<?=json_encode($chart_type==='line'?'line':'bar')?>,
  data:{
    labels:<?=json_encode($chart_labels)?>,
    datasets:[{
      label:<?=json_encode(str_replace('_',' ',ucwords($cols[1]??'','_')))?>,
      data:<?=json_encode($chart_values)?>,
      backgroundColor:'rgba(26,82,118,0.75)',
      borderColor:'#1A5276',borderWidth:1.5,tension:0.3,fill:false
    }]
  },
  options:{responsive:true,plugins:{legend:{display:false}},
    scales:{y:{beginAtZero:true,grid:{color:'#EAECEE'}},x:{grid:{display:false},ticks:{maxRotation:38,font:{size:10}}}}}
});
</script>
<?php endif; ?>
</body></html>