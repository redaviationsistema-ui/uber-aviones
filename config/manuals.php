<?php

return [
    'source_path' => env('MANUALES_SOURCE_PATH', 'C:\\manuales_aeronaves'),
    'allowed_extensions' => ['pdf'],
    'default_ata_chapter_code' => env('MANUALES_DEFAULT_ATA_CHAPTER', '100'),
    'default_ata_subchapter_code' => env('MANUALES_DEFAULT_ATA_SUBCHAPTER', '100-10'),
    'sidecar_extensions' => ['json', 'txt'],
    'chunk' => [
        'max_chars' => 5000,
        'min_chars' => 400,
        'summary_length' => 280,
        'keyword_limit' => 12,
    ],
];
