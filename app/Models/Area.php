<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A named neighborhood area (a BID, say) as a group of Metro stations. See App\Support\AreaReports.
 */
#[Fillable(['name', 'slug', 'stations', 'verify'])]
class Area extends Model
{
    protected function casts(): array
    {
        return ['stations' => 'array', 'verify' => 'boolean'];
    }

    public function stationNames(): string
    {
        return collect($this->stations)->map(fn ($code) => config("stoop.stations.$code", $code))->join(', ');
    }
}
