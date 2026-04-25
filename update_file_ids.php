<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';

// Mapping of sample IDs to their Google Drive file IDs
// Based on rclone lsjson output
$updates = [
    1 => '1kkD7QIiGbp4cLoa7Iyt6ofjq1XErsGK4',  // sample_69ebd689c84a67.57868087.jpg
    10 => '1RO1EuPHZNd6WSKlSyHJ1WG-oMwCx5LRR', // sample_69ebf52b300e73.45409065.jpeg
];

foreach ($updates as $sampleId => $fileId) {
    \Illuminate\Support\Facades\DB::table('samples')
        ->where('id', $sampleId)
        ->update(['file_id' => $fileId]);
    echo "✓ Sample #$sampleId → file_id: $fileId\n";
}

echo "\nDone!\n";
