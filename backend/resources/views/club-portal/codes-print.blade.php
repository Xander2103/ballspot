<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('club_portal.codes.print_sheet.title') }} – {{ $club->name }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        body { background: #f5f7fa; }
        .sheet { max-width: 210mm; margin: 0 auto; }
        .code-card { border: 1px dashed #adb5bd; border-radius: 10px; background: #fff; padding: 14px; text-align: center; break-inside: avoid; page-break-inside: avoid; height: 100%; }
        .code-card .club { font-weight: 700; font-size: 1.05rem; }
        .code-card .qr { width: 150px; height: 150px; margin: 10px auto; }
        .code-card .qr img, .code-card .qr canvas { width: 150px !important; height: 150px !important; }
        .code-card .code { font-family: SFMono-Regular, Menlo, Consolas, monospace; font-size: clamp(1.05rem, 4.6vw, 1.4rem); font-weight: 700; letter-spacing: .12em; white-space: nowrap; }
        .code-card .how { font-size: .8rem; color: #495057; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet { max-width: none; }
            @page { margin: 10mm; }
        }
    </style>
</head>
<body>
<div class="sheet py-4 px-3">
    <div class="no-print d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
        <div>
            <a href="{{ route('club-portal.codes.index', $club) }}" class="small text-muted">&larr; {{ __('club_portal.codes.print_sheet.back') }}</a>
            <h1 class="h5 mb-0 mt-1">{{ __('club_portal.codes.print_sheet.title') }} · {{ $club->name }}</h1>
            <div class="small text-muted">{{ $code->label ?? __('club_portal.common.none') }} · {{ __('club_portal.codes.grants_role') }}: {{ __('club_portal.roles.' . $code->grants_role->value) }} · {{ __('club_portal.codes.col_uses') }}: {{ $code->uses_count }}@if($code->max_uses)/{{ $code->max_uses }}@endif · {{ __('club_portal.codes.col_expires') }}: {{ $code->expires_at?->format('Y-m-d') ?? __('club_portal.common.never') }}</div>
        </div>
        @unless($code->isRevoked())<button type="button" class="btn btn-primary btn-sm" onclick="window.print()">{{ __('club_portal.codes.print_sheet.print') }}</button>@endunless
    </div>

    @if($code->isRevoked() || $code->isExpired() || $code->isExhausted())
        <div class="alert alert-danger">{{ __('club_portal.codes.print_sheet.unusable') }}</div>
    @endif

    @unless($code->isRevoked())
    <div class="row g-3">
        @for($i = 0; $i < $copies; $i++)
            <div class="col-6">
                <div class="code-card">
                    <div class="club">{{ $club->name }}</div>
                    <div class="how">{{ __('club_portal.codes.print_sheet.join_line') }}</div>
                    <div class="qr" data-qr="{{ $payload }}" role="img" aria-label="{{ __('club_portal.codes.print_sheet.qr_label', ['code' => $formatted]) }}"></div>
                    <div class="code">{{ $formatted }}</div>
                    <div class="how mt-1">{{ __('club_portal.codes.print_sheet.how') }}</div>
                    @if($code->expires_at)<div class="how text-muted">{{ __('club_portal.codes.print_sheet.valid_until', ['date' => $code->expires_at->format('Y-m-d')]) }}</div>@endif
                </div>
            </div>
        @endfor
    </div>
    @endunless
</div>
@unless($code->isRevoked())
{{-- Client-side QR rendering (contracts §6: same pinned CDN library as the admin sheet; no server package). --}}
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function () {
    if (typeof QRCode === 'undefined') return;
    document.querySelectorAll('[data-qr]').forEach(function (el) {
        new QRCode(el, { text: el.getAttribute('data-qr'), width: 300, height: 300, correctLevel: QRCode.CorrectLevel.M });
    });
}());
</script>
@endunless
</body>
</html>
