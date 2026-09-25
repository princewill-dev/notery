<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Save extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'writeup', 'max_views', 'views_count', 'delete_after'];

    protected $casts = [
        'delete_after' => 'datetime',
    ];

    public function images()
    {
        return $this->hasMany(SaveImage::class);
    }
}
