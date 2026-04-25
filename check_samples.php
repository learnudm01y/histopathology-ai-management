<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';

// Initialize the app
$app->make('Illuminate\Contracts\Http\Kernel');

// Query samples
$samples = \App\Models\Sample::select('id', 'file_id', 'storage_status', 'gdrive_source_id', 'file_name')->limit(15)->get();

echo "=== Sample Database Check ===\n\n";
foreach ($samples as $s) {
    echo "ID: " . $s->id . " | file_id: " . ($s->file_id ?: 'NULL') . " | gdrive_source_id: " . ($s->gdrive_source_id ?: 'NULL') . " | storage_status: " . $s->storage_status . "\n";
}
