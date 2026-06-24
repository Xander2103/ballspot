<div class="alert alert-warning alert-dismissible fade show d-flex align-items-start gap-2 py-2 px-3 mb-3" role="alert" style="font-size:.85rem;">
    <span style="font-size:1rem;">⚠️</span>
    <div>
        <strong>Content safety reminder:</strong>
        Challenge images and data are stored in the database and <code>storage/app/public/</code>.
        Run <code>php artisan ballspot:backup-content</code> before any destructive database commands
        (<code>migrate:fresh</code>, <code>migrate:reset</code>, etc.) to avoid losing real content.
    </div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
