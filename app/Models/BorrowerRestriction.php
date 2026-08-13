<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BorrowerRestriction extends Model
{
    protected $fillable = ['borrower_user_id', 'restriction_type', 'reason', 'effective_from', 'effective_to', 'status', 'imposed_by_user_id', 'lifted_by_user_id', 'penalty_id', 'billing_statement_id', 'incident_id'];

    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'effective_to' => 'datetime'];
    }
}
