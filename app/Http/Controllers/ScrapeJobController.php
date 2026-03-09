<?php

namespace App\Http\Controllers;

use App\Jobs\RunScrapeJob;
use App\Models\ScrapeJob;
use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ScrapeJobController extends Controller
{
    /**
     * Show the scraping dashboard page.
     */
    public function index()
    {
        $websites = Website::where('user_id', Auth::id())
            ->where('is_active', true)
            ->get(['id', 'name', 'url', 'platform']);

        $jobs = ScrapeJob::where('user_id', Auth::id())
            ->with('website:id,name,url')
            ->latest()
            ->paginate(15);

        return Inertia::render('Scraping/Index', [
            'websites' => $websites,
            'jobs'     => $jobs,
        ]);
    }

    /**
     * Start a new scrape job — dispatches to queue.
     */
    public function start(Request $request)
    {
        $validated = $request->validate([
            'website_ids' => 'required|array|min:1',
            'website_ids.*' => 'exists:websites,id',
        ]);

        // Verify ownership
        $websites = Website::whereIn('id', $validated['website_ids'])
            ->where('user_id', Auth::id())
            ->get();

        if ($websites->isEmpty()) {
            return back()->withErrors(['website_ids' => 'No valid websites selected.']);
        }

        $createdJobs = [];

        foreach ($websites as $website) {
            // Prevent duplicate running jobs for same website
            $alreadyRunning = ScrapeJob::where('website_id', $website->id)
                ->whereIn('status', ['pending', 'running'])
                ->exists();

            if ($alreadyRunning) continue;

            $job = ScrapeJob::create([
                'user_id'    => Auth::id(),
                'website_id' => $website->id,
                'status'     => 'pending',
            ]);

            RunScrapeJob::dispatch($job);
            $createdJobs[] = $job->id;
        }

        return back()->with('success', count($createdJobs) . ' scrape job(s) queued.');
    }

    /**
     * Polling endpoint — returns current status of all user's active jobs.
     * Called every 3 seconds from the frontend.
     */
    public function poll()
    {
        $jobs = ScrapeJob::where('user_id', Auth::id())
            ->with('website:id,name,url')
            ->whereIn('status', ['pending', 'running'])
            ->orWhere(function ($q) {
                $q->where('user_id', Auth::id())
                  ->where('status', 'completed')
                  ->where('updated_at', '>=', now()->subMinutes(5));
            })
            ->latest()
            ->get()
            ->map(fn($j) => [
                'id'                => $j->id,
                'website_name'      => $j->website->name ?? '',
                'website_url'       => $j->website->url  ?? '',
                'status'            => $j->status,
                'scraped_products'  => $j->scraped_products,
                'total_products'    => $j->total_products,
                'progress_percent'  => $j->progress_percent,
                'platform_detected' => $j->platform_detected,
                'error_message'     => $j->error_message,
                'output_filename'   => $j->output_filename,
                'download_url'      => $j->download_url,
                'started_at'        => $j->started_at?->diffForHumans(),
                'completed_at'      => $j->completed_at?->diffForHumans(),
            ]);

        return response()->json($jobs);
    }

    /**
     * Full job history with pagination.
     */
    public function history()
    {
        $jobs = ScrapeJob::where('user_id', Auth::id())
            ->with('website:id,name,url')
            ->latest()
            ->paginate(20);

        return response()->json($jobs);
    }

    /**
     * Download the CSV file for a completed job.
     */
    public function download(ScrapeJob $job)
    {
        abort_if($job->user_id !== Auth::id(), 403);

        if ($job->status !== 'completed' || !$job->output_filename) {
            abort(404, 'File not available.');
        }

        $path = 'exports/' . $job->output_filename;

        if (!Storage::exists($path)) {
            abort(404, 'File no longer exists.');
        }

        return Storage::download($path, $job->output_filename);
    }

    /**
     * Cancel a pending or running job.
     */
    public function cancel(ScrapeJob $job)
    {
        abort_if($job->user_id !== Auth::id(), 403);

        if (in_array($job->status, ['pending', 'running'])) {
            $job->update(['status' => 'failed', 'error_message' => 'Cancelled by user.']);
        }

        return back()->with('success', 'Job cancelled.');
    }
}