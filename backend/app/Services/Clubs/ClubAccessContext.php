<?php

namespace App\Services\Clubs;

/**
 * Per-request memo of ACTIVE club memberships, keyed "club:user", so policies,
 * ClubAccess and resources resolve a membership once per request
 * (contracts §3.5). Bound as a scoped singleton in AppServiceProvider.
 *
 * Scoped bindings are not flushed between HTTP requests inside one PHPUnit
 * test (the app instance is shared), so the memo also resets itself whenever
 * the current Request object changes. ClubMembershipService forgets entries
 * it mutates.
 */
final class ClubAccessContext
{
    /** @var array<string, \App\Models\ClubMember|null> */
    private array $memberships = [];

    private ?int $requestId = null;

    public function has(int $clubId, int $userId): bool
    {
        $this->syncRequest();

        return array_key_exists($this->key($clubId, $userId), $this->memberships);
    }

    public function get(int $clubId, int $userId): ?\App\Models\ClubMember
    {
        $this->syncRequest();

        return $this->memberships[$this->key($clubId, $userId)] ?? null;
    }

    public function put(int $clubId, int $userId, ?\App\Models\ClubMember $member): void
    {
        $this->syncRequest();
        $this->memberships[$this->key($clubId, $userId)] = $member;
    }

    public function forget(int $clubId, int $userId): void
    {
        unset($this->memberships[$this->key($clubId, $userId)]);
    }

    public function flush(): void
    {
        $this->memberships = [];
    }

    private function key(int $clubId, int $userId): string
    {
        return $clubId . ':' . $userId;
    }

    private function syncRequest(): void
    {
        $id = app()->bound('request') ? spl_object_id(app('request')) : null;
        if ($id !== $this->requestId) {
            $this->requestId   = $id;
            $this->memberships = [];
        }
    }
}
