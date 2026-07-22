<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Sport;

class SportController extends Controller
{
    public function index()
    {
        $sports = Sport::orderBy('sort_order')
            ->orderBy('name')
            ->get();
        return view('admin.sports.index', compact('sports'));
    }

    public function toggle(Sport $sport)
    {
        // Safety: football is the current live sport and must always stay active.
        if ($sport->slug === 'football' && $sport->is_active) {
            return redirect('/admin/sports')->with('error', 'Football cannot be deactivated.');
        }

        $sport->update(['is_active' => !$sport->is_active]);
        $state = $sport->is_active ? 'activated' : 'deactivated';
        return redirect('/admin/sports')->with('success', "Sport {$state}.");
    }
}
