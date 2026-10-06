<?php

namespace App\Enums;

enum OrderOutboxStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processed = 'processed';
    case Failed = 'failed';
}
