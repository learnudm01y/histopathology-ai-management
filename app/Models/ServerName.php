<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerName extends Model
{
    protected $table = 'servers_names';

    protected $fillable = [
        'name',
        'type',
        'api_url',
        'api_key',
        'runpod_api_key',
        'runpod_network_volume_id',
        'runpod_template_id',
        'host',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Hide secrets from JSON serialization
    protected $hidden = ['api_key', 'runpod_api_key'];

    public function samples(): HasMany
    {
        return $this->hasMany(Sample::class, 'patch_server_id');
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            'local'    => 'Local Server',
            'external' => 'External Server',
            default    => 'Unknown',
        };
    }

    public function getTypeBadgeClass(): string
    {
        return match ($this->type) {
            'local'    => 'success',
            'external' => 'info',
            default    => 'secondary',
        };
    }
}
