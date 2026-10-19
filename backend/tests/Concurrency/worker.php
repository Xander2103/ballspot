<?php

/*
 * One concurrency worker: boots the app against the same throwaway test DB as
 * the calling ConcurrencyTestCase, waits for the shared start time, performs a
 * single club action and prints one JSON line {ok, code, ...}.
 *
 * Usage (only ever spawned by ConcurrencyTestCase::runWorkers()):
 *   php tests/Concurrency/worker.php <scenario> <json-payload> <start-at-unix-float>
 */

use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\User;
use App\Services\Clubs\ClubCompetitionPlayService;
use App\Services\Clubs\ClubMembershipService;
use App\Services\PackPlayService;
use Tests\Concurrency\ConcurrencyTestCase;

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$scenario, $payloadJson, $startAt] = [$argv[1] ?? '', $argv[2] ?? '{}', (float) ($argv[3] ?? 0)];
$payload = json_decode($payloadJson, true) ?: [];

if (($problem = ConcurrencyTestCase::unsafeTarget()) !== null) {
    echo json_encode(['ok' => false, 'code' => 'unsafe_target', 'detail' => $problem]), "\n";
    exit(1);
}

config(['ballspot.clubs.enabled' => true]);

// Release every worker at the same instant to maximise overlap.
while (microtime(true) < $startAt) {
    usleep(500);
}

try {
    $user = User::findOrFail($payload['user_id']);
    $result = ['ok' => true];

    switch ($scenario) {
        case 'join':
            $out = app(ClubMembershipService::class)->joinWithCode($user, $payload['code'], '127.0.0.' . ($payload['user_id'] % 250 + 1));
            $result['joined'] = $out['joined'];
            break;

        case 'competition_start':
            $competition = ClubCompetition::findOrFail($payload['competition_id']);
            $out = app(ClubCompetitionPlayService::class)->start(
                $user,
                Club::findOrFail($competition->club_id),
                $competition,
                ChallengePack::findOrFail($payload['pack_id']),
                (int) $payload['attempt_number'],
            );
            $result['attempt_id'] = $out['attempt']->id;
            $result['created']    = $out['created'];
            break;

        case 'competition_guess':
            app(ClubCompetitionPlayService::class)->guess(
                $user,
                ClubCompetitionAttempt::findOrFail($payload['attempt_id']),
                (int) $payload['challenge_id'],
                (float) $payload['x'],
                (float) $payload['y'],
            );
            break;

        case 'pack_start':
            $attempt = app(PackPlayService::class)->startOrResume($user, ChallengePack::findOrFail($payload['pack_id']));
            $result['attempt_id'] = $attempt->id;
            break;

        default:
            $result = ['ok' => false, 'code' => 'unknown_scenario'];
    }
} catch (App\Exceptions\ClubDomainException $e) {
    $result = ['ok' => false, 'code' => $e->errorCode()];
} catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
    $result = ['ok' => false, 'code' => 'http_' . $e->getStatusCode(), 'message' => $e->getMessage()];
} catch (Throwable $e) {
    $result = ['ok' => false, 'code' => 'exception', 'class' => get_class($e), 'message' => $e->getMessage()];
}

echo json_encode($result), "\n";
