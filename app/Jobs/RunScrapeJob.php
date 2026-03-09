<?php

namespace App\Jobs;

use App\Models\ScrapeJob;
use App\Services\ScraperEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunScrapeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;   // 1 hour max
    public int $tries   = 1;      // no auto-retry for long jobs

    public function __construct(public ScrapeJob $scrapeJob) {}

    public function handle(): void
    {
        $job = $this->scrapeJob;

        // Mark as running
        $job->update([
            'status'     => 'running',
            'started_at' => now(),
        ]);

        try {
            $engine = new ScraperEngine([
                'delay_ms'   => 1200,
                'max_retries'=> 3,
                'timeout'    => 30,
                'output_dir' => storage_path('app/exports'),

                // Progress callback — updates DB as products are scraped
                'on_progress' => function (int $scraped, int $total, string $platform) use ($job) {
                    $job->update([
                        'scraped_products'  => $scraped,
                        'total_products'    => $total,
                        'platform_detected' => $platform,
                    ]);
                },

                // Error callback
                'on_error' => function (string $message) use ($job) {
                    $job->increment('error_count');
                    Log::warning("[ScrapeJob #{$job->id}] Error: {$message}");
                },
            ]);

            $stats = $engine->scrape($job->website->url);

            $job->update([
                'status'            => 'completed',
                'scraped_products'  => $stats['total_products'],
                'total_products'    => $stats['total_products'],
                'error_count'       => $stats['total_errors'],
                'platform_detected' => $stats['platform'],
                'output_filename'   => basename($stats['output_file']),
                'completed_at'      => now(),
            ]);

        } catch (\Throwable $e) {
            Log::error("[ScrapeJob #{$job->id}] Fatal: " . $e->getMessage());

            $job->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->scrapeJob->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
            'completed_at'  => now(),
        ]);
    }
}