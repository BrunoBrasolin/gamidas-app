<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Study library files
    |--------------------------------------------------------------------------
    |
    | Images and PDFs attached to study pages live on a private disk and are
    | only ever served through an authorized controller. Sizes are in KB.
    | The PHP limits (upload_max_filesize / post_max_size) on the server must
    | be at least as large as the biggest limit below.
    |
    */

    'disk' => env('STUDY_DISK', 'local'),

    'max_image_kb' => (int) env('STUDY_MAX_IMAGE_KB', 10 * 1024),

    'max_pdf_kb' => (int) env('STUDY_MAX_PDF_KB', 30 * 1024),

    // Size of the editor document (JSON) of a single page.
    'max_content_kb' => (int) env('STUDY_MAX_CONTENT_KB', 2 * 1024),

    // Attachments removed from a page's text are kept this long, so undo in
    // the editor still finds them, then deleted by study:prune-attachments.
    'prune_after_hours' => (int) env('STUDY_PRUNE_AFTER_HOURS', 24),

];
