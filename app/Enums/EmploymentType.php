<?php

namespace App\Enums;

enum EmploymentType: string
{
    case Employee = 'EMPLOYEE';
    case Faculty = 'FACULTY';
    case Staff = 'STAFF';
}
