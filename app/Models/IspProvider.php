<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IspProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'support_phone',
        'ticket_url',
        'account_rep_name',
        'account_rep_mobile',
        'account_rep_phone',
        'account_rep_email',
        'notes',
    ];

    public function circuits(): HasMany
    {
        return $this->hasMany(Circuit::class);
    }
}
