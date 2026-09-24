<?php

return [
    'attachments' => ['max_count' => (int) env('WORK_ORDER_ATTACHMENT_MAX_COUNT', 3), 'max_size_kb' => (int) env('WORK_ORDER_ATTACHMENT_MAX_SIZE_KB', 10240)],
    'execution' => [
        'require_before_evidence' => (bool) env('WORK_ORDER_REQUIRE_BEFORE_EVIDENCE', false),
        'require_continuation_evidence' => (bool) env('WORK_ORDER_REQUIRE_CONTINUATION_EVIDENCE', false),
        'require_completion_photo' => (bool) env('WORK_ORDER_REQUIRE_COMPLETION_PHOTO', true),
    ],
];
