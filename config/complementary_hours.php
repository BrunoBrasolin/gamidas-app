<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Complementary hours certificates
    |--------------------------------------------------------------------------
    |
    | Certificates (PDF or image) live on a private disk and are only served
    | through an authorized controller. The size is in KB and must fit the PHP
    | limits (upload_max_filesize / post_max_size) on the server.
    |
    */

    'disk' => env('COMPLEMENTARY_HOURS_DISK', 'local'),

    'max_certificate_kb' => (int) env('COMPLEMENTARY_HOURS_MAX_CERTIFICATE_KB', 10 * 1024),

    // Length of the Markdown report of a single activity, in characters.
    'max_report_chars' => 100_000,

];
