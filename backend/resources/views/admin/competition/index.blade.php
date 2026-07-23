@extends('admin.layout')

@section('title', 'Competition')

@section('content')
<h1 class="h3 mb-3">Competition Period</h1>

<p class="text-muted small mb-3">
    Controls the recurring daily-challenge leaderboard window and the top-finishers badge.
    This is config-driven for now (a full settings UI is future work).
</p>

<div class="card shadow-sm" style="max-width: 560px;">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4 text-muted">Period type</dt>
            <dd class="col-sm-8"><span class="badge badge-active">{{ ucfirst($period['period_type']) }}</span></dd>

            <dt class="col-sm-4 text-muted">Visible label</dt>
            <dd class="col-sm-8">{{ $period['period_label'] }}</dd>

            <dt class="col-sm-4 text-muted">Current window</dt>
            <dd class="col-sm-8">{{ $period['period_start'] }} → {{ $period['period_end'] }}</dd>
        </dl>
    </div>
</div>

<div class="alert alert-info small mt-3" style="max-width: 560px;">
    To change the period, set <code>BALLPICKER_COMPETITION_PERIOD=weekly|monthly</code> (and optionally
    <code>BALLPICKER_COMPETITION_LABEL</code>) in the backend <code>.env</code>, then
    <code>php artisan config:clear</code>. The leaderboard and top-finishers badge follow this setting.
</div>
@endsection
