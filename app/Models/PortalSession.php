<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalSession extends Model
{
    protected $fillable = ['code', 'duration_minutes', 'expires_at', 'status', 'peer_ids'];

    protected $casts = [
        'peer_ids' => 'array',
        'expires_at' => 'datetime',
    ];

    public function messages()
    {
        return $this->hasMany(PortalMessage::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where('expires_at', '>', now());
    }
}
