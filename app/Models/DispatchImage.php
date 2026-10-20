<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[Fillable(['path', 'alt', 'width', 'height'])]
class DispatchImage extends Model
{
    public $timestamps = false;

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
