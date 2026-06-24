import argparse
# import os
# import re
import pandas as pd
from pathlib import Path

NULL = r'\N'   # MySQL NULL sentinel for LOAD DATA INFILE (searched onnline)

#    Helpers 
# ATP and WTA share overlapping integer player IDs in the Sackmann data.
# We prefix every player_id with 'A' (ATP) or 'W' (WTA) to guarantee uniqueness
# across both tours in a single player table.
PREFIX = {'atp': 'A', 'wta': 'W'}

def prefix_pid(raw_id, tour):
    """Prefix a raw Sackmann player ID with tour letter, or return NULL.
    Also rejects '0' and '199999' which are Sackmann placeholder IDs
    for unknown/bye players — inserting them causes duplicate PK errors
    when multiple matches reference the same placeholder.
    """
    if pd.isna(raw_id) or str(raw_id).strip() in ('', 'nan', 'None', '0', '199999'):
        return NULL
    return f"{PREFIX.get(tour, 'X')}{str(raw_id).strip()}"

def to_mysql_date(val):
    """Convert YYYYMMDD int/str to YYYY-MM-DD, or return NULL."""
    try:
        s = str(int(val))
        if len(s) == 8:
            return f"{s[:4]}-{s[4:6]}-{s[6:]}"
    except (ValueError, TypeError):
        pass
    return NULL


def safe(val, default=NULL):
    """Return val or NULL if missing/NaN."""
    if pd.isna(val) or str(val).strip() in ('', 'nan', 'None'):
        return default
    return str(val).strip()


def to_hand(val):
    mapping = {'R': 'R', 'L': 'L', 'U': 'U', 'A': 'U'}
    return mapping.get(str(val).strip().upper(), 'U')


def base_tourn_id(tourney_id):
    """Extract base tournament code from Sackmann tourney_id.
    '2023-520' -> '520', '2020-0410' -> '0410'
    """
    parts = str(tourney_id).split('-', 1)
    return parts[1] if len(parts) == 2 else str(tourney_id)


def make_match_id(tourney_id, match_num, tour=''):
    """Build a unique match ID, including tour prefix to prevent ATP/WTA collisions
    on shared tourney codes (e.g. Davis Cup D001 exists in both tours).
    """
    prefx = PREFIX.get(tour, '')
    try:
        return f"{prefx}{tourney_id}-{int(match_num):04d}"
    except (ValueError, TypeError):
        # Some doubles files have non-numeric match_num (e.g. 'USA' from Davis Cup).
        # Fall back to the raw value as-is.
        return f"{prefx}{tourney_id}-{str(match_num).strip()}"
    
def count_sets(score):
    # helper to streamline set calculations on data extraction
    if pd.isna(score) or any(x in str(score) for x in ['RET','W/O','DEF']):
        return None
    return len(str(score).strip().split())


# ── Load singles match files ──────────────────────────────────────────────────
def load_singles_matches(atp_dir, wta_dir, years):
    frames = []
    for base_dir, tour in [(atp_dir, 'atp'), (wta_dir, 'wta')]:
        if base_dir is None:
            continue
        for year in years:
            path = Path(base_dir) / f"{tour}_matches_{year}.csv"
            if not path.exists():
                print(f"  [skip] {path}")
                continue
            df = pd.read_csv(path, dtype=str, low_memory=False)
            df['_tour'] = tour
            df['_year'] = year
            frames.append(df)
            print(f"  [loaded] {path}  ({len(df)} rows)")
    if not frames:
        return pd.DataFrame()
    return pd.concat(frames, ignore_index=True)


# ── Load doubles match files ──────────────────────────────────────────────────
def load_doubles_matches(atp_dir, wta_dir, years):
    frames = []
    for base_dir, tour in [(atp_dir, 'atp'), (wta_dir, 'wta')]:
        if base_dir is None:
            continue
        for year in years:
            path = Path(base_dir) / f"{tour}_matches_doubles_{year}.csv"
            if not path.exists():
                continue
            df = pd.read_csv(path, dtype=str, low_memory=False, index_col=False) # index_col=False since columns seem to be shifted in csv
            df['_tour'] = tour
            df['_year'] = year
            frames.append(df)
            print(f"  [loaded doubles] {path}  ({len(df)} rows)")
    if not frames:
        return pd.DataFrame()
    return pd.concat(frames, ignore_index=True)


# ── Load player files ─────────────────────────────────────────────────────────
def load_players(atp_dir, wta_dir):
    frames = []
    for base_dir, fname, tour in [(atp_dir, 'atp_players.csv', 'atp'),
                                   (wta_dir, 'wta_players.csv', 'wta')]:
        if base_dir is None:
            continue
        path = Path(base_dir) / fname
        if not path.exists():
            print(f"  [skip] {path}")
            continue
        df = pd.read_csv(path, dtype=str)
        df['_tour'] = tour   # tag so extract_players can prefix IDs
        frames.append(df)
        print(f"  [loaded] {path}  ({len(df)} rows)")
    if not frames:
        return pd.DataFrame()
    return pd.concat(frames, ignore_index=True)


# ── Load ranking files ────────────────────────────────────────────────────────
def load_rankings(base_dir, prefix, tour):
    """Load decade-chunked ranking files.
    ATP format: no header, columns = rank_date, rank_pos, player_id, rank_pts
    WTA format: has header,  columns = ranking_date, rank, player, points, tours
    """
    frames = []
    if base_dir is None:
        return pd.DataFrame()
    for f in sorted(Path(base_dir).glob(f"{prefix}_rankings_*.csv")):
        with open(f, 'r', encoding='utf-8', errors='replace') as fh:
            first = fh.readline().strip()
        has_header = not first.split(',')[0].isdigit()

        if has_header:
            df = pd.read_csv(f, dtype=str)
            df = df.rename(columns={
                'ranking_date': 'rank_date',
                'rank':         'rank_pos',
                'player':       'player_id',
                'points':       'rank_pts',
            })
        else:
            df = pd.read_csv(f, header=None,
                             names=['rank_date', 'rank_pos', 'player_id', 'rank_pts'],
                             dtype=str)

        df['_tour'] = tour
        frames.append(df)
        print(f"  [loaded rankings] {f}  ({len(df)} rows)")
    if not frames:
        return pd.DataFrame()
    return pd.concat(frames, ignore_index=True)


# ── Extract tables ────────────────────────────────────────────────────────────
def extract_players(singles_df, player_df):
    """Build player table from player CSV, supplemented by match data."""
    rows = {}

    # From dedicated player CSV (has _tour column set in load_players)
    if not player_df.empty:
        for _, r in player_df.iterrows():
            tour = r.get('_tour', 'atp')
            pid  = prefix_pid(r.get('player_id'), tour)
            if pid == NULL:
                continue
            first = safe(r.get('name_first', ''))
            last  = safe(r.get('name_last', ''))
            name  = f"{first} {last}".strip() if first != NULL and last != NULL else (first if first != NULL else last)
            rows[pid] = {
                'player_id':   pid,
                'full_name':   name if name else NULL,
                'nationality': safe(r.get('ioc', NULL)),
                'birthdate':   to_mysql_date(r.get('dob', NULL)),
                'hand':        to_hand(r.get('hand', 'U')),
                'height_cm':   safe(r.get('height', NULL)),
            }

    # Supplement from match data (catches players missing from player CSV)
    if not singles_df.empty:
        for side in ('winner', 'loser'):
            sub = singles_df[[f'{side}_id', f'{side}_name', f'{side}_ioc',
                               f'{side}_hand', f'{side}_ht', '_tour']].drop_duplicates(subset=[f'{side}_id','_tour'])
            for _, r in sub.iterrows():
                tour = r.get('_tour', 'atp')
                pid  = prefix_pid(r[f'{side}_id'], tour)
                if pid == NULL or pid in rows:
                    continue
                rows[pid] = {
                    'player_id':   pid,
                    'full_name':   safe(r.get(f'{side}_name', NULL)),
                    'nationality': safe(r.get(f'{side}_ioc', NULL)),
                    'birthdate':   NULL,
                    'hand':        to_hand(r.get(f'{side}_hand', 'U')),
                    'height_cm':   safe(r.get(f'{side}_ht', NULL)),
                }

    return pd.DataFrame(list(rows.values()))


def extract_tournaments(singles_df, doubles_df):
    """Deduplicate tournaments across all match files."""
    rows = {}
    for df in [singles_df, doubles_df]:
        if df is None or df.empty:
            continue
        for _, r in df[['tourney_id','tourney_name','surface','tourney_level']].drop_duplicates().iterrows():
            tid = base_tourn_id(r['tourney_id'])
            if tid in rows:
                continue
            rows[tid] = {
                'tourn_id': tid,
                'name':     safe(r.get('tourney_name', NULL)),
                'surface':  safe(r.get('surface', NULL)),
                'level':    safe(r.get('tourney_level', NULL)),
                'location': NULL,   # not in source data; can be added manually later I believre
            }
    return pd.DataFrame(list(rows.values()))


def extract_editions(singles_df, doubles_df):
    rows = {}
    for df in [singles_df, doubles_df]:
        if df is None or df.empty:
            continue
        # Explicitly select only the two columns we need to avoid column bleed
        sub = df[['tourney_id', 'draw_size']].copy()
        
        sub = sub.drop_duplicates(subset=['tourney_id'])
        for _, r in sub.iterrows():
            raw_tid = str(r['tourney_id']).strip()
            # Year must be the numeric prefix before the first '-'
            parts = raw_tid.split('-', 1)
            if len(parts) != 2 or not parts[0].isdigit():
                continue   # skip malformed tourney_id rows
            year = parts[0]
            tid  = parts[1]
            key = (tid, year)
            if key in rows:
                continue
            # draw_size must be a positive integer; reject level letters ('A','M',etc.)
            ds_raw = str(r.get('draw_size', '')).strip()
            draw_size = ds_raw if ds_raw.isdigit() else NULL
            rows[key] = {
                'tourn_id':    tid,
                'ed_year':     year,
                'prize_money': NULL,   # not reliably in CSVs
                'draw_size':   draw_size,
            }
    return pd.DataFrame(list(rows.values()))


def extract_matches_and_players(singles_df):
    """Return (matches_df, match_player_df) for singles matches."""
    match_rows = []
    mp_rows = []

    if singles_df.empty:
        return pd.DataFrame(), pd.DataFrame()

    for _, r in singles_df.iterrows():
        tour  = r.get('_tour', 'atp')
        tid   = base_tourn_id(r['tourney_id'])
        year  = str(r['tourney_id']).split('-')[0]
        mid   = make_match_id(r['tourney_id'], r.get('match_num', 0))

        match_rows.append({
            'match_id':   mid,
            'tourn_id':   tid,
            'ed_year':    year,
            'score':      safe(r.get('score', NULL)),
            'round':      safe(r.get('round', NULL)),
            'match_date': to_mysql_date(r.get('tourney_date', NULL)),
            'match_type': 'singles',
            'num_sets':   count_sets(r.get('score'))
        })

        for side, res in [('winner','W'), ('loser','L')]:
            pid = prefix_pid(r.get(f'{side}_id'), tour)
            if pid == NULL:
                continue
            col = 'w' if res == 'W' else 'l'
            mp_rows.append({
                'match_id':      mid,
                'player_id':     pid,
                'result':        res,
                'seed':          safe(r.get(f'{side}_seed', NULL)),
                'aces':          safe(r.get(f'{col}_ace',    NULL)),
                'double_faults': safe(r.get(f'{col}_df',     NULL)),
                'serve_pts':     safe(r.get(f'{col}_svpt',   NULL)),
                'first_in':      safe(r.get(f'{col}_1stIn',  NULL)),
                'first_won':     safe(r.get(f'{col}_1stWon', NULL)),
                'second_won':    safe(r.get(f'{col}_2ndWon', NULL)),
                'bp_saved':      safe(r.get(f'{col}_bpSaved',NULL)),
                'bp_faced':      safe(r.get(f'{col}_bpFaced',NULL)),
            })

    matches_df = pd.DataFrame(match_rows)
    mp_df      = pd.DataFrame(mp_rows)

    if not matches_df.empty:
        matches_df = matches_df.drop_duplicates(subset=['match_id'], keep='first')
    if not mp_df.empty:
        mp_df = mp_df.drop_duplicates(subset=['match_id', 'player_id'], keep='first')

    return matches_df, mp_df


def extract_doubles(doubles_df):
    """Return (teams_df, doubles_matches_df, team_match_df)."""
    if doubles_df is None or doubles_df.empty:
        return pd.DataFrame(), pd.DataFrame(), pd.DataFrame()

    team_map = {}   # (p1, p2) -> team_id
    team_rows = []
    dm_rows = []
    tm_rows = []
    team_counter = 1

    for _, r in doubles_df.iterrows():
        tour = r.get('_tour', 'atp')
        raw_tid = str(r['tourney_id'])
        tid     = base_tourn_id(raw_tid)   # FK → edition
        year    = raw_tid.split('-')[0]
        mid     = make_match_id(raw_tid, r.get('match_num', 0), tour) + '-d'

        dm_rows.append({
            'match_id':   mid,
            'tourn_id':   tid,
            'ed_year':    year,
            'score':      safe(r.get('score', NULL)),
            'round':      safe(r.get('round', NULL)),
            'match_date': to_mysql_date(r.get('tourney_date', NULL)),
            'match_type': 'doubles',
            'num_sets':   count_sets(r.get('score'))
        })

        for side, res in [('winner','W'), ('loser','L')]:
            p1 = prefix_pid(r.get(f'{side}1_id'), tour)
            p2 = prefix_pid(r.get(f'{side}2_id'), tour)
            if p1 == NULL or p2 == NULL:
                continue
            key = tuple(sorted([p1, p2]))
            if key not in team_map:
                team_map[key] = team_counter
                team_rows.append({
                    'team_id':    team_counter,
                    'player1_id': key[0],
                    'player2_id': key[1],
                    'start_date': NULL,
                    'end_date':   NULL,
                })
                team_counter += 1
            tm_rows.append({
                'match_id': mid,
                'team_id':  team_map[key],
                'result':   res,
            })

    teams_df   = pd.DataFrame(team_rows)
    dm_df      = pd.DataFrame(dm_rows)
    tm_df      = pd.DataFrame(tm_rows)

    if not dm_df.empty:
        dm_df = dm_df.drop_duplicates(subset=['match_id'], keep='first')
    if not tm_df.empty:
        tm_df = tm_df.drop_duplicates(subset=['match_id', 'team_id'], keep='first')
        # enforce max 2 teams per match (1 winner, 1 loser) —
        # extra rows are artifacts of misaligned old-format CSV columns
        tm_df = (tm_df
                 .sort_values('result')                        # L before W — keeps both if valid
                 .groupby('match_id')
                 .head(2)
                 .reset_index(drop=True))

    return teams_df, dm_df, tm_df


def extract_rankings(rank_df, player_ids=None):
    rows = []
    if rank_df.empty:
        return pd.DataFrame()
    for _, r in rank_df.iterrows():
        tour = r.get('_tour', 'atp')
        pid  = prefix_pid(r.get('player_id'), tour)
        if pid == NULL:
            continue
        if player_ids is not None and pid not in player_ids:
            continue
        rows.append({
            'player_id': pid,
            'rank_date': to_mysql_date(r.get('rank_date')),
            'rank_pos':  safe(r.get('rank_pos', NULL)),
            'rank_pts':  safe(r.get('rank_pts', NULL)),
        })
    df = pd.DataFrame(rows)
    if df.empty:
        return df
    # Sackmann decade files overlap with current file — deduplicate on (player_id, rank_date),
    # keeping the first occurrence (earlier file = more historically stable entry).
    df = df.drop_duplicates(subset=['player_id', 'rank_date'], keep='first')
    return df


# ── Write CSV ─────────────────────────────────────────────────────────────────
def write_csv(df, path):
    r"""Write DataFrame to CSV with \N for NULL, no header, tab-separated."""
    if df.empty:
        print(f"  [empty, skipping] {path}")
        return
    df.to_csv(path, index=False, header=False, sep='\t', na_rep=r'\N')
    print(f"  [wrote] {path}  ({len(df)} rows)")


# ── Main ──────────────────────────────────────────────────────────────────────
def main():
    parser = argparse.ArgumentParser(description='Tennis DB ')
    parser.add_argument('--atp',   default=None, help='Path to tennis_atp repo')
    parser.add_argument('--wta',   default=None, help='Path to tennis_wta repo')
    parser.add_argument('--out',   default='./clean_data', help='Output directory')
    parser.add_argument('--years', nargs=2, type=int, default=[2010, 2023],
                        metavar=('START', 'END'), help='Year range (inclusive)')
    args = parser.parse_args()

    years = list(range(args.years[0], args.years[1] + 1))
    out   = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    print(f"\n=== Loading raw data (years {years[0]}-{years[-1]}) ===")
    singles_df  = load_singles_matches(args.atp, args.wta, years)
    doubles_df  = load_doubles_matches(args.atp, args.wta, years)
    player_df   = load_players(args.atp, args.wta)
    atp_rank_df = load_rankings(args.atp, 'atp', 'atp')
    wta_rank_df = load_rankings(args.wta, 'wta', 'wta')
    rank_df     = pd.concat([atp_rank_df, wta_rank_df], ignore_index=True) \
                  if not (atp_rank_df.empty and wta_rank_df.empty) else pd.DataFrame()

    print(f"\n Extracting tables -  ->")

    players_out = extract_players(singles_df, player_df)
    player_ids  = set(players_out['player_id'].tolist())

    tournaments_out = extract_tournaments(singles_df, doubles_df)
    editions_out    = extract_editions(singles_df, doubles_df)

    matches_out, match_player_out = extract_matches_and_players(singles_df)

    teams_out, dbl_matches_out, team_match_out = extract_doubles(doubles_df)

    # Combine singles + doubles matches
    all_matches_out = pd.concat([matches_out, dbl_matches_out], ignore_index=True) \
                      if not dbl_matches_out.empty else matches_out

    rankings_out = extract_rankings(rank_df, player_ids) if not rank_df.empty else pd.DataFrame()

    print(f"\n=== Writing clean CSVs to {out} ===")
    write_csv(players_out,      out / 'players.csv')
    write_csv(tournaments_out,  out / 'tournaments.csv')
    write_csv(editions_out,     out / 'editions.csv')
    write_csv(all_matches_out,  out / 'matches.csv')
    write_csv(match_player_out, out / 'match_player.csv')
    write_csv(teams_out,        out / 'doubles_teams.csv')
    write_csv(team_match_out,   out / 'team_matches.csv')
    write_csv(rankings_out,     out / 'rankings.csv')

    print(f"\n=== Done. Load with load.sql ===")


if __name__ == '__main__':
    main()