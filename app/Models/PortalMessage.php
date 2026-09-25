<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['portal_session_id', 'type', 'file_name', 'content', 'is_encrypted', 'image_path', 'image_mime', 'image_size', 'peer_id', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
        'is_encrypted' => 'boolean',
    ];

    public function session()
    {
        return $this->belongsTo(PortalSession::class, 'portal_session_id');
    }
}
