<?php

use App\Contexts\Identity\Domain\Models\UserIdentity;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', UserIdentity::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    'jwt' => [
        'issuer' => env('AUTH_JWT_ISSUER', env('APP_URL', 'http://localhost')),
        'audience' => env('AUTH_JWT_AUDIENCE', 'schoolos-api'),
        'algorithm' => 'RS256',
        'access_ttl' => 600,
        'refresh_ttl' => 1209600,
        'current_kid' => env('AUTH_JWT_CURRENT_KID', 'schoolos-current'),
        'private_key' => env('AUTH_JWT_PRIVATE_KEY'),
        'public_keys' => json_decode(env('AUTH_JWT_PUBLIC_KEYS', '{}'), true) ?: [],
    ],

    'password_recovery' => [
        'challenge_ttl' => (int) env('AUTH_PASSWORD_RESET_TTL', 900),
        'max_attempts' => (int) env('AUTH_PASSWORD_RESET_MAX_ATTEMPTS', 5),
        'common_passwords' => array_values(array_filter(array_map(
            static fn (string $password): string => mb_strtolower(trim($password)),
            explode(',', (string) env('AUTH_PASSWORD_COMMON_DENYLIST', 'passwordpassword,schoolos-password,letmeinplease')),
        ))),
    ],

    'contact_verification' => [
        'challenge_ttl' => (int) env('AUTH_CONTACT_VERIFICATION_TTL', 600),
        'max_attempts' => (int) env('AUTH_CONTACT_VERIFICATION_MAX_ATTEMPTS', 5),
    ],

    'registration_verification' => [
        'challenge_ttl' => (int) env('AUTH_REGISTRATION_VERIFICATION_TTL', 600),
        'max_attempts' => (int) env('AUTH_REGISTRATION_VERIFICATION_MAX_ATTEMPTS', 5),
    ],

    'guardian_invitation' => [
        'challenge_ttl' => (int) env('AUTH_GUARDIAN_INVITATION_TTL', 600),
        'max_attempts' => (int) env('AUTH_GUARDIAN_INVITATION_MAX_ATTEMPTS', 5),
    ],

    'lockout' => [
        'threshold' => (int) env('AUTH_LOCKOUT_THRESHOLD', 5),
        'failure_window_minutes' => (int) env('AUTH_LOCKOUT_FAILURE_WINDOW', 15),
        'durations_minutes' => [15, 60, 1440],
    ],

    'school_invitations' => [
        'ttl_days' => (int) env('AUTH_SCHOOL_INVITATION_TTL_DAYS', 7),
    ],

    'platform' => [
        'break_glass_max_minutes' => (int) env('AUTH_PLATFORM_BREAK_GLASS_MAX_MINUTES', 60),
    ],

];
