<?php
    require_once('mysql.inc.php');

    header('Content-Type: application/json');

    $term = isset($_GET['term']) ? trim($_GET['term']) : '';
    if (strlen($term) < 2) { echo json_encode([]); exit; }

    $stmt = $db->prepare(
        "SELECT player_id, full_name
        FROM player
        WHERE full_name LIKE ?
        ORDER BY full_name
        "
    );
    $like = '%' . $term . '%';
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $players = [];
    while ($row = $result->fetch_assoc()) {
        $players[] = ['id' => $row['player_id'], 'name' => $row['full_name']];
    }
    echo json_encode($players);
?>