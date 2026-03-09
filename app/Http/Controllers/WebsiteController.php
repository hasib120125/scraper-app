<?php

namespace App\Http\Controllers;

use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class WebsiteController extends Controller
{
    public function index()
    {
        $websites = Website::where('user_id', Auth::id())
            ->withCount('scrapeJobs')
            ->with(['scrapeJobs' => fn($q) => $q->latest()->limit(1)])
            ->latest()
            ->get();

        return Inertia::render('Websites/Index', [
            'websites' => $websites,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'url'      => 'required|url|max:500',
            'platform' => 'nullable|in:shopify,woocommerce,magento,generic',
            'notes'    => 'nullable|string|max:1000',
        ]);

        Website::create([
            ...$validated,
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', 'Website added successfully.');
    }

    public function update(Request $request, Website $website)
    {
        abort_if($website->user_id !== Auth::id(), 403);

        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'url'       => 'required|url|max:500',
            'platform'  => 'nullable|in:shopify,woocommerce,magento,generic',
            'notes'     => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        $website->update($validated);

        return back()->with('success', 'Website updated.');
    }

    public function destroy(Website $website)
    {
        abort_if($website->user_id !== Auth::id(), 403);

        $website->delete();

        return back()->with('success', 'Website deleted.');
    }
}