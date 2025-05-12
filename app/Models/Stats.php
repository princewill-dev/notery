<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stats extends Model
{
    use HasFactory;

    protected $fillable = ['saves_count', 'decodes_count'];
    
    // Get the current stats or create a new record if none exists
    public static function getStats()
    {
        // Since we're only tracking global stats, we'll always use ID 1
        return self::firstOrCreate(['id' => 1], [
            'saves_count' => 0,
            'decodes_count' => 0
        ]);
    }
    
    // Increment saves count
    public static function incrementSaves()
    {
        $stats = self::getStats();
        $stats->increment('saves_count');
        return $stats;
    }
    
    // Increment decodes count
    public static function incrementDecodes()
    {
        $stats = self::getStats();
        $stats->increment('decodes_count');
        return $stats;
    }
}
