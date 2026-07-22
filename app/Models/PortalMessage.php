<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['portal_session_id', 'type', 'file_name', 'content', 'image_path', 'image_mime', 'image_size', 'peer_id', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(PortalSession::class, 'portal_session_id');
    }
}
