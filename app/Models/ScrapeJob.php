<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeJob extends Model
{
    protected $fillable = [
        'user_id', 'website_id', 'status',
        'total_products', 'scraped_products', 'error_count',
        'platform_detected', 'error_message', 'output_filename',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Append these virtual attributes to JSON/array output
    protected $appends = ['progress_percent', 'download_url'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    // Laravel 9+ modern accessor syntax
    protected function progressPercent(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!$this->total_products) return 0;
                return (int) round(($this->scraped_products / $this->total_products) * 100);
            }
        );
    }

    protected function downloadUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->status !== 'completed' || !$this->output_filename) return null;
                return route('scraping.download', $this->id);
            }
        );
    }
}