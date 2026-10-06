<?php

namespace App\Http\RateLimiting;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ApiRateLimiters
{
    public static function register(): void
    {
        foreach (array_keys(config('rate-limits.policies')) as $name) {
            RateLimiter::for($name, function (Request $request) use ($name): Limit {
                $policy = config('rate-limits.policies.'.$name);
                $identity = in_array($name, ['auth-register', 'catalogue-read', 'health-read'], true)
                    ? 'ip:'.self::ipIdentity($request)
                    : ($request->user()->is_admin ? 'admin:' : 'customer:').$request->user()->getAuthIdentifier();

                return ($policy['minutes'] === 60 ? Limit::perHour($policy['attempts']) : Limit::perMinute($policy['attempts']))->by($identity);
            });
        }

        RateLimiter::for('auth-login', function (Request $request): array {
            $email = $request->input('email');
            $normalizedEmail = is_string($email) ? Str::lower(trim($email)) : '';
            $account = hash_hmac('sha256', $normalizedEmail, (string) config('app.key'));
            $ip = self::ipIdentity($request);

            return [
                Limit::perMinute(config('rate-limits.login.ip_per_minute'))->by('ip:'.$ip),
                Limit::perMinute(config('rate-limits.login.account_ip_per_minute'))->by('account-ip:'.$account.':'.$ip),
                Limit::perMinute(config('rate-limits.login.account_per_minute'))->by('account:'.$account),
            ];
        });
    }

    private static function ipIdentity(Request $request): string
    {
        $ip = $request->ip();
        abort_unless(is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false, 400);

        return hash('sha256', inet_pton($ip));
    }
}
