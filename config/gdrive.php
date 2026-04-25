<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Drive via rclone
    |--------------------------------------------------------------------------
    | All pathology slide files are stored exclusively on Google Drive.
    | rclone is used as the transfer layer (OAuth configured in rclone.conf).
    |
    | Folder architecture on Drive:
    |   {root_folder}/{username}/{data_source}/{category}/{sample_folder}/
    */

    'rclone_path'   => env('GDRIVE_RCLONE_PATH', 'rclone'),
    'rclone_config' => env('GDRIVE_RCLONE_CONFIG'),
    'remote_name'   => env('GDRIVE_REMOTE_NAME', 'alhayah'),
    'root_folder'   => env('GDRIVE_ROOT_FOLDER', 'samples'),

];
