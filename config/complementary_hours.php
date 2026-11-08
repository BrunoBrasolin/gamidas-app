<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Complementary hours attachments
    |--------------------------------------------------------------------------
    |
    | Attachments (certificates, the filled-in report and other files) live on
    | a private disk and are only served through an authorized controller. The
    | size is in KB, per file, and must fit the PHP limits (upload_max_filesize
    | / post_max_size / max_file_uploads) on the server.
    |
    */

    'disk' => env('COMPLEMENTARY_HOURS_DISK', 'local'),

    'max_attachment_kb' => (int) env('COMPLEMENTARY_HOURS_MAX_ATTACHMENT_KB', 10 * 1024),

    // Files sent at once, and kept by a single activity.
    'max_uploads' => 10,
    'max_attachments' => 20,

];
