<?php

return ['attachments' => ['max_count' => (int) env('WORK_ORDER_ATTACHMENT_MAX_COUNT', 3), 'max_size_kb' => (int) env('WORK_ORDER_ATTACHMENT_MAX_SIZE_KB', 10240)]];
