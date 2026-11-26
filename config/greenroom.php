<?php

return [
    'name' => 'Greenroom',
    'storage_url' => env('UNTASKED_STORAGE_URL'),
    'storage_token' => env('UNTASKED_STORAGE_TOKEN'),
    // Largest slide deck accepted, in kilobytes.
    'max_slide_kb' => 51200,
    // Largest recorded webinar video accepted, in kilobytes (~200 MB).
    'max_recording_kb' => 204800,
];
