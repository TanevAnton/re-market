<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'name_bg', 'name_en', 'slug', 'region_bg', 'region_en',
        'lat', 'lng', 'population',
    ];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function name(): string
    {
        return app()->getLocale() === 'bg' ? $this->name_bg : $this->name_en;
    }

    public function users(): HasMany    { return $this->hasMany(User::class); }
    public function listings(): HasMany { return $this->hasMany(Listing::class); }
}
