<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AdminEmailCodeNotification;
use App\Support\MailDevelopmentLogger;
use Illuminate\Support\Facades\Cache;

class AdminOtpService
{
    public const PURPOSE_LOGIN = 'login';

    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public const TTL_MINUTES = 15;

    public function send(User $user, string $purpose, string $subject, string $intro): void
    {
        $code = (string) random_int(100000, 999999);

        MailDevelopmentLogger::info('OTP admin: generando código', [
            'email' => $user->email,
            'purpose' => $purpose,
        ]);

        Cache::put(
            $this->cacheKey($user->id, $purpose),
            $code,
            now()->addMinutes(self::TTL_MINUTES),
        );

        $user->notify(new AdminEmailCodeNotification(
            code: $code,
            subjectLine: $subject,
            introLine: $intro,
            expiresMinutes: self::TTL_MINUTES,
        ));

        MailDevelopmentLogger::info('OTP admin: notificación encolada/enviada', [
            'email' => $user->email,
            'purpose' => $purpose,
        ]);
    }

    public function verify(User $user, string $purpose, string $code): bool
    {
        $stored = Cache::get($this->cacheKey($user->id, $purpose));

        if ($stored === null || ! hash_equals((string) $stored, trim($code))) {
            return false;
        }

        Cache::forget($this->cacheKey($user->id, $purpose));

        return true;
    }

    protected function cacheKey(int $userId, string $purpose): string
    {
        return "admin_otp:{$purpose}:{$userId}";
    }
}
