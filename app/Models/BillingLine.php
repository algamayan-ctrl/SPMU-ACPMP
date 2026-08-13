<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingLine extends Model
{
    protected $fillable = ['billing_statement_id', 'penalty_id', 'incident_id', 'line_type', 'description', 'basis', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
