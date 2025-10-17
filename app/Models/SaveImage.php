<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaveImage extends Model
{
    use HasFactory;

    protected $fillable = ['save_id', 'image', 'image_mime', 'path', 'size', 'is_encrypted'];

    public function parentSave()
    {
        return $this->belongsTo(Save::class);
    }
}
