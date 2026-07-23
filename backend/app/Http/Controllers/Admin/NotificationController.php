<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Services\ExpoPushService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private ExpoPushService $push) {}

    // GET /admin/notifications
    public function index()
    {
        $notifications = AdminNotification::with('creator')
            ->latest()
            ->paginate(20);

        $pushEnabled = (bool) config('ballspot.notifications.push_enabled');

        return view('admin.notifications.index', compact('notifications', 'pushEnabled'));
    }

    // POST /admin/notifications
    public function store(Request $request)
    {
        $data = $this->validated($request);

        $notification = AdminNotification::create([
            'title'              => $data['title'],
            'body'               => $data['body'],
            'target_type'        => $data['target_type'],
            'send_at'            => $data['send_at'] ?? null,
            'status'             => AdminNotification::STATUS_DRAFT,
            'created_by_user_id' => $request->user()->id,
        ]);

        // Optional immediate delivery. Without push infrastructure the service
        // leaves it as a draft ("not sent") rather than faking a send.
        if ($request->boolean('send_now')) {
            $notification = $this->push->sendAnnouncement($notification);

            return redirect('/admin/notifications')
                ->with('success', $this->sendSummary($notification));
        }

        return redirect('/admin/notifications')
            ->with('success', 'Announcement saved as draft.');
    }

    // POST /admin/notifications/{adminNotification}/send
    public function send(AdminNotification $adminNotification)
    {
        $adminNotification = $this->push->sendAnnouncement($adminNotification);

        return redirect('/admin/notifications')
            ->with('success', $this->sendSummary($adminNotification));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title'       => ['required', 'string', 'max:120'],
            // Plain text only — no HTML is ever rendered (Blade escapes on output).
            'body'        => ['required', 'string', 'max:500'],
            'target_type' => ['required', 'in:' . AdminNotification::TARGET_ALL . ',' . AdminNotification::TARGET_OPTED_IN],
            'send_at'     => ['nullable', 'date'],
        ]);
    }

    private function sendSummary(AdminNotification $n): string
    {
        if ($n->status === AdminNotification::STATUS_DRAFT) {
            return 'Push is not configured — announcement saved but not sent.';
        }

        $meta = $n->metadata ?? [];

        return "Announcement sent to {$meta['sent']}/{$meta['recipients']} device(s)"
            . (($meta['failed'] ?? 0) > 0 ? " ({$meta['failed']} failed)." : '.');
    }
}
