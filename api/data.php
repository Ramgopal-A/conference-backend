<?php
function getThemes($pdo) {
    $themes = $pdo->query("SELECT * FROM tbl_theme")->fetchAll();
    $subthemes = $pdo->query("SELECT * FROM tbl_theme_subtheme ORDER BY display_order ASC")->fetchAll();
    $contents = $pdo->query("SELECT * FROM tbl_theme_subtheme_content ORDER BY display_order ASC")->fetchAll();

    $result = [];
    foreach ($themes as $theme) {
        $themeObj = [
            "theme_id" => $theme['theme_id'],
            "theme_name" => $theme['theme_name'],
            "theme_desc" => $theme['theme_desc'],
            "subthemes" => []
        ];

        foreach ($subthemes as $sub) {
            if ($sub['theme_id'] == $theme['theme_id']) {
                $subObj = [
                    "subtheme_id" => $sub['subtheme_id'],
                    "sub_theme_name" => $sub['sub_theme_name'],
                    "contents" => array_values(array_filter($contents, function($c) use ($sub) {
                        return $c['subtheme_id'] == $sub['subtheme_id'];
                    }))
                ];
                $themeObj['subthemes'][] = $subObj;
            }
        }
        $result[] = $themeObj;
    }
    echo json_encode($result);
}

function getVenues($pdo) {
    $venues = $pdo->query("SELECT * FROM venues")->fetchAll();
    echo json_encode($venues);
}

function getVenueFacilities($pdo) {
    $facilities = $pdo->query("SELECT * FROM venue_facilities ORDER BY display_order ASC")->fetchAll();
    echo json_encode($facilities);
}

function getAccommodations($pdo) {
    $hotels = $pdo->query("SELECT * FROM accommodations ORDER BY created_at ASC")->fetchAll();
    $student = $pdo->query("SELECT * FROM student_accommodations")->fetch();
    
    echo json_encode([
        "hotels" => $hotels,
        "student_accommodation" => $student ?: null
    ]);
}

function getGenericData($pdo, $table) {
    $allowed_tables = ['speakers' => 'keynote_speakers', 'committees' => 'committees'];
    if (array_key_exists($table, $allowed_tables)) {
        $dbTable = $allowed_tables[$table];
        $data = $pdo->query("SELECT * FROM $dbTable")->fetchAll();
        echo json_encode($data);
    }
}
?>