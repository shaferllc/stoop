<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['giver_id', 'dispatch_id'])]
class HalfSmoke extends Model
{
    public const UPDATED_AT = null;
}
