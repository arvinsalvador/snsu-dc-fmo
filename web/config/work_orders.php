<?php

return [
    'attachments' => [
        'max_count' => (int) env('WORK_ORDER_ATTACHMENT_MAX_COUNT', 3),
        'max_size_kb' => (int) env('WORK_ORDER_ATTACHMENT_MAX_SIZE_KB', 10240),
        'image_max_dimension' => (int) env('WORK_ORDER_IMAGE_MAX_DIMENSION', 1920),
        'image_max_input_dimension' => (int) env('WORK_ORDER_IMAGE_MAX_INPUT_DIMENSION', 12000),
        'image_max_pixels' => (int) env('WORK_ORDER_IMAGE_MAX_PIXELS', 40000000),
        'image_quality' => (int) env('WORK_ORDER_IMAGE_QUALITY', 82),
        'png_compression' => (int) env('WORK_ORDER_PNG_COMPRESSION', 6),
        'thumbnail_max_dimension' => (int) env('WORK_ORDER_THUMBNAIL_MAX_DIMENSION', 480),
    ],
    'execution' => [
        'require_before_evidence' => (bool) env('WORK_ORDER_REQUIRE_BEFORE_EVIDENCE', false),
        'require_continuation_evidence' => (bool) env('WORK_ORDER_REQUIRE_CONTINUATION_EVIDENCE', false),
        'require_completion_photo' => (bool) env('WORK_ORDER_REQUIRE_COMPLETION_PHOTO', true),
    ],
];
