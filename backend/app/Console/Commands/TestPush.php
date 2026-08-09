<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Notifications\FcmSender;
use Illuminate\Console\Command;

/**
 * "Why didn't my phone buzz?" — answered one link at a time.
 *
 * A push crosses four boundaries before it reaches a handset (config, Google's auth, our
 * device registry, Google's delivery) and a failure at any of them looks identical from
 * the outside: silence. Worse, the real path is asynchronous — the send happens on a queue
 * worker, so a broken link doesn't even surface as a failed request.
 *
 * So this walks the chain in order and stops at the first thing that's actually wrong,
 * deliberately bypassing both the queue and NotificationPolicy: if this succeeds and real
 * messages still don't arrive, the fault is in *those*, and that is a useful thing to have
 * narrowed down.
 */
class TestPush extends Command
{
    protected $signature = 'push:test {user? : Email or id of the person to buzz}';

    protected $description = 'Check the push pipeline end to end, and optionally send a test notification';

    public function handle(FcmSender $sender): int
    {
        if (! $this->checkConfig($sender)) {
            return self::FAILURE;
        }

        if (! $this->checkAuth($sender)) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Registered devices: '.DeviceToken::count());

        foreach (DeviceToken::with('user')->get() as $device) {
            $this->line(sprintf(
                '  #%d  %-8s %-30s …%s',
                $device->id,
                $device->platform,
                $device->user?->email ?? 'orphaned',
                substr($device->token, -12),
            ));
        }

        $target = $this->argument('user');

        if ($target === null) {
            $this->newLine();
            $this->comment('Pass a user (email or id) to actually send one: php artisan push:test you@example.com');

            return self::SUCCESS;
        }

        return $this->send($sender, $target);
    }

    private function checkConfig(FcmSender $sender): bool
    {
        if (! FcmSender::enabled()) {
            $this->error('FCM_CREDENTIALS is not set.');
            $this->line('  Nothing is queued at all while this is empty — see FcmSender::enabled().');

            return false;
        }

        $this->info('✓ FCM_CREDENTIALS is set');

        if (! $sender->configured()) {
            $this->error('…but it could not be parsed into a service account.');
            // Far and away the most common cause, and invisible from the value itself: an
            // env dashboard that keeps the quotes you typed around the value.
            $raw = trim((string) config('services.fcm.credentials'));
            $this->line('  Value starts with: '.substr($raw, 0, 24).'…');
            $this->line('  It must be the service-account JSON (starting `{`) or base64 of it.');
            $this->line('  Check storage/logs/laravel.log — the parse failure is logged with a reason.');

            return false;
        }

        $this->info('✓ Credentials parsed');

        return true;
    }

    /** The step that proves the private key really signs and the account really exists. */
    private function checkAuth(FcmSender $sender): bool
    {
        $credentials = $this->invoke($sender, 'credentials');
        $token = $this->invoke($sender, 'accessToken', $credentials);

        if (! is_string($token)) {
            $this->error('✗ Google would not issue an access token.');
            $this->line('  Usually one of: the key was revoked, the clock is badly skewed,');
            $this->line('  or "Firebase Cloud Messaging API (V1)" is not enabled on the project.');
            $this->line('  The response body is in storage/logs/laravel.log.');

            return false;
        }

        $this->info('✓ Google issued an access token (project: '.$credentials['project_id'].')');

        return true;
    }

    private function send(FcmSender $sender, string $target): int
    {
        $user = User::where('email', $target)->orWhere('id', $target)->first();

        if ($user === null) {
            $this->error("No user matches \"{$target}\".");

            return self::FAILURE;
        }

        $devices = $user->deviceTokens()->get();

        if ($devices->isEmpty()) {
            $this->error("{$user->email} has no registered devices.");
            $this->line('  The app posts its token to /api/device-tokens on launch. If nothing');
            $this->line('  arrived: no google-services.json in the build, notifications denied,');
            $this->line('  or the app is pointed at a different API than this one.');

            return self::FAILURE;
        }

        // Straight to the sender: no queue, no policy. A worker that isn't running and a
        // channel that's muted are both real causes of silence, and both would hide the
        // answer this command exists to give.
        $sent = $sender->send($devices, [
            'type' => 'test',
            'title' => 'Side Chat',
            'body' => 'Test notification — push is working.',
            'tag' => 'push-test',
        ]);

        if ($sent === 0) {
            $this->error('✗ FCM rejected every device.');
            $this->line('  A rejected-as-dead token is deleted; check the log for the status.');

            return self::FAILURE;
        }

        $this->info("✓ Accepted by FCM for {$sent} of {$devices->count()} device(s).");
        $this->line('  Background the app before testing — a foregrounded app suppresses on purpose.');

        return self::SUCCESS;
    }

    /** The pipeline's private steps are worth testing individually, and only from here. */
    private function invoke(FcmSender $sender, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod(FcmSender::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($sender, ...$args);
    }
}
