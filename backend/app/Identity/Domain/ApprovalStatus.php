<?php

namespace App\Identity\Domain;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
}
