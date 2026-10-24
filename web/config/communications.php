<?php

return [
    'message_max_length' => (int) env('COMMUNICATION_MESSAGE_MAX_LENGTH', 5000),
    'attachments' => [
        'max_count' => (int) env('COMMUNICATION_ATTACHMENT_MAX_COUNT', 3),
        'max_size_kb' => (int) env('COMMUNICATION_ATTACHMENT_MAX_SIZE_KB', 10240),
    ],
];
