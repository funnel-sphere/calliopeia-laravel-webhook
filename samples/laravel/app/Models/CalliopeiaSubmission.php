<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CalliopeiaSubmission extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['request', 'ticket', 'result'];

    protected function casts(): array
    {
        return ['request' => 'encrypted:array', 'ticket' => 'encrypted:array', 'result' => 'encrypted:array'];
    }
}
