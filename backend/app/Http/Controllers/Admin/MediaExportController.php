<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sport;
use App\Services\MediaExportService;
use App\Support\AppLog;
use Illuminate\Http\Request;

/**
 * Admin → Media Export: filter challenge media and download it as one ZIP
 * (guess/reveal images + manifest.json + manifest.csv). Read-only — see
 * MediaExportService for the safety rules (public disk only, no traversal,
 * missing files reported not fatal, file cap, temp zip deleted after send).
 */
class MediaExportController extends Controller
{
    public function __construct(private MediaExportService $export) {}

    public function index(Request $request)
    {
        $filters = $this->export->normalizeFilters($request->query());
        $active  = array_filter($filters, fn ($v, $k) => $v !== '' && !($k === 'image_type' && $v === 'both'), ARRAY_FILTER_USE_BOTH) !== [];

        return view('admin.media-export.index', [
            'filters'  => $filters,
            'summary'  => $this->export->summary($filters),
            'preview'  => $this->export->preview($filters, 20),
            'sports'   => Sport::orderBy('sort_order')->orderBy('name')->get(),
            'packs'    => $this->export->packOptions(),
            'hasFilters' => $active,
            'maxFiles' => $this->export->maxFiles(),
        ]);
    }

    public function download(Request $request)
    {
        $filters = $this->export->normalizeFilters($request->all());

        try {
            $path = $this->export->build($filters, $request->user()?->id);
        } catch (\RuntimeException $e) {
            // Friendly, expected outcomes (nothing matched / too many files /
            // zip could not be written) — back to the form with the message.
            AppLog::warn('admin.media_export_refused', ['reason' => class_basename($e), 'admin_user_id' => $request->user()?->id]);

            return redirect()->route('admin.media-export.index', $filters)->withErrors(['export' => $e->getMessage()]);
        }

        return response()->download($path, $this->export->downloadName($path), [
            'Content-Type'  => 'application/zip',
            'Cache-Control' => 'no-store, private',
        ])->deleteFileAfterSend(true);
    }
}
