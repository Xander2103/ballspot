<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Sport;
use App\Services\SportReadinessService;
use Illuminate\Http\Request;

class SportController extends Controller
{
    public function __construct(private SportReadinessService $readiness) {}

    public function index()
    {
        $sports = Sport::orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $readiness = $sports->mapWithKeys(fn (Sport $s) => [$s->id => $this->readiness->for($s)]);

        return view('admin.sports.index', compact('sports', 'readiness'));
    }

    /**
     * Set a sport's availability status: active / coming_soon / hidden.
     * Football is protected — it must stay active (the live experience).
     */
    public function updateStatus(Request $request, Sport $sport)
    {
        $data = $request->validate([
            'status' => ['required', 'in:' . implode(',', Sport::STATUSES)],
        ]);

        if ($sport->slug === 'football' && $data['status'] !== Sport::STATUS_ACTIVE) {
            return redirect('/admin/sports')->with('error', 'Football must stay active.');
        }

        $sport->update(['status' => $data['status']]);

        return redirect('/admin/sports')->with('success', "\u{201C}{$sport->name}\u{201D} is now {$this->label($data['status'])}.");
    }

    private function label(string $status): string
    {
        return match ($status) {
            Sport::STATUS_ACTIVE      => 'Active',
            Sport::STATUS_COMING_SOON => 'Coming soon',
            Sport::STATUS_HIDDEN      => 'Hidden',
            default                   => $status,
        };
    }
}
