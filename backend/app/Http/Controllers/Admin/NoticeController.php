<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppNotice;
use App\Support\AppLog;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin → Notices: the single in-app notice per placement (Home / Daily
 * Challenge card for now). Saved in place; the API decides visibility.
 */
class NoticeController extends Controller
{
    public function index()
    {
        return view('admin.notices.index', [
            'notice'     => AppNotice::forPlacement(AppNotice::PLACEMENT_HOME_DAILY_CARD),
            'placements' => AppNotice::PLACEMENTS,
            'types'      => AppNotice::TYPES,
            'languages'  => Locale::supported(),
            'messageMax' => AppNotice::MESSAGE_MAX,
            'isLive'     => AppNotice::activeFor(AppNotice::PLACEMENT_HOME_DAILY_CARD, Locale::default()) !== null,
        ]);
    }

    public function update(Request $request)
    {
        $rules = [
            'placement' => ['required', Rule::in(AppNotice::PLACEMENTS)],
            'enabled'   => ['nullable', 'boolean'],
            'type'      => ['required', Rule::in(AppNotice::TYPES)],
            'starts_at' => ['nullable', 'date'],
            'ends_at'   => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
        foreach (Locale::supported() as $lang) {
            $rules['message_' . $lang] = ['nullable', 'string', 'max:' . AppNotice::MESSAGE_MAX];
        }
        $data = $request->validate($rules);

        $data['enabled'] = (bool) ($data['enabled'] ?? false);
        foreach (Locale::supported() as $lang) {
            $data['message_' . $lang] = trim((string) ($data['message_' . $lang] ?? '')) ?: null;
        }

        // An enabled notice with no text in any language would render nothing —
        // refuse it so the admin does not believe something is live.
        if ($data['enabled'] && !array_filter(array_intersect_key($data, array_flip(array_map(fn ($l) => 'message_' . $l, Locale::supported()))))) {
            return back()->withInput()->withErrors(['message_en' => 'Add the message in at least one language before enabling the notice.']);
        }

        $notice = AppNotice::forPlacement($data['placement']);
        $notice->fill($data)->save();

        // Category only — never the message text.
        AppLog::event('notice.saved', [
            'placement' => $notice->placement,
            'enabled'   => $notice->enabled,
            'type'      => $notice->type,
            'languages' => array_keys($notice->messages()),
            'admin_id'  => $request->user()?->id,
        ]);

        return redirect()->route('admin.notices.index')->with('success', 'Notice saved.');
    }
}
