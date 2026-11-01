@if($player->anonymized_at)
    <span class="badge badge-archived">Deleted</span>
@else
    <span class="badge badge-soft-ok">Active</span>
    @unless($player->email_verified_at)
        <span class="badge badge-soft-warn">Email not verified</span>
    @endunless
@endif
@if($player->is_admin)
    <span class="badge badge-soft">Admin</span>
@endif
