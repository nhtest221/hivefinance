<?php

namespace App\Enums;

enum SystemRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case FinanceManager = 'finance-manager';
    case Accountant = 'accountant';
    case FinanceStaff = 'finance-staff';
    case Auditor = 'auditor';
    case ServiceAccount = 'service-account';

    public function requiresMfa(): bool
    {
        return in_array($this, [self::Owner, self::FinanceManager], true);
    }
}
