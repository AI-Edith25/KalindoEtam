<?php

namespace App\Enums;

enum ImportBatchStatus: string
{
    case UPLOADED = 'uploaded';
    case MAPPED = 'mapped';
    case PREVIEWED = 'previewed';
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
