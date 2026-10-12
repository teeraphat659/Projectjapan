<?php
$file = 'C:/xampp/htdocs/projectjapan/Front-end/data/api.php';
$content = file_get_contents($file);

$content = str_replace("', sound_file'", "', {\$table}.sound_file'", $content);
$content = str_replace("', video_url'", "', {\$table}.video_url'", $content);

$content = preg_replace(
    '/(SELECT )character_id( AS id, )japanese_char( AS character_jp, )romaji(, )image_file( AS stroke_image\{\$soundField\}\{\$videoField\})/',
    '$1{\$table}.character_id$2{\$table}.japanese_char$3{\$table}.romaji$4{\$table}.image_file$5, s.path_data AS strokes_json, s.animation_style, s.stroke_duration',
    $content
);

$content = preg_replace(
    '/(FROM \{\$table\} \s*)WHERE/m',
    "$1 LEFT JOIN japanese_character_strokes s ON {\$table}.character_id = s.character_id\n                  WHERE",
    $content
);

$content = str_replace('char_group = :group', '{$table}.char_group = :group', $content);
$content = str_replace('char_group = :norm_group', '{$table}.char_group = :norm_group', $content);
$content = str_replace('LOWER(char_type)', 'LOWER({$table}.char_type)', $content);
$content = str_replace('char_group IS NOT NULL', '{$table}.char_group IS NOT NULL', $content);
$content = str_replace('TRIM(char_group)', 'TRIM({$table}.char_group)', $content);
$content = preg_replace('/LOWER\(TRIM\(REPLACE\(char_group/', 'LOWER(TRIM(REPLACE({$table}.char_group', $content);
$content = str_replace('LOWER(romaji)', 'LOWER({$table}.romaji)', $content);
$content = str_replace('TRIM(romaji)', 'TRIM({$table}.romaji)', $content);
$content = str_replace('LOWER(char_group)', 'LOWER({$table}.char_group)', $content);
$content = preg_replace('/, character_id"/', ', {$table}.character_id"', $content);

file_put_contents($file, $content);
echo "Done";
