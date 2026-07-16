<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\OauthSetting;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\ControlPlaneMode;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;
use LogicException;

class FortifyServiceProvider extends ServiceProvider
{
    private const array LOOPBACK_PROXY_ADDRESSES = [
        '127.0.0.1',
        '::1',
    ];

    private const int MAX_CONTROL_PLANE_PROXY_ADDRESSES = 16;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->instance(RegisterResponse::class, new class implements RegisterResponse
        {
            public function toResponse($request)
            {
                // First user (root) will be redirected to /settings instead of / on registration.
                if ($request->user()->currentTeam->id === 0) {
                    return redirect()->route('settings.index');
                }

                return redirect(RouteServiceProvider::HOME);
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::registerView(function () {
            $isFirstUser = User::count() === 0;

            $settings = instanceSettings();
            if (! $settings->is_registration_enabled) {
                return redirect()->route('login');
            }

            return view('auth.register', [
                'isFirstUser' => $isFirstUser,
            ]);
        });

        Fortify::loginView(function () {
            $settings = instanceSettings();
            $enabled_oauth_providers = OauthSetting::where('enabled', true)->get();
            $users = User::count();
            if ($users == 0) {
                // If there are no users, redirect to registration
                return redirect()->route('register');
            }

            return view('auth.login', [
                'is_registration_enabled' => $settings->is_registration_enabled,
                'enabled_oauth_providers' => $enabled_oauth_providers,
            ]);
        });

        Fortify::authenticateUsing(function (Request $request) {
            $email = strtolower($request->email);
            $user = User::where('email', $email)->with('teams')->first();
            if (
                $user &&
                Hash::check($request->password, $user->password)
            ) {
                $user->updated_at = now();
                $user->save();

                // Check if user has a pending invitation they haven't accepted yet
                $invitation = TeamInvitation::whereEmail($email)->first();
                if ($invitation && $invitation->isValid()) {
                    // User is logging in for the first time after being invited
                    // Attach them to the invited team if not already attached
                    if (! $user->teams()->where('team_id', $invitation->team->id)->exists()) {
                        $user->teams()->attach($invitation->team->id, ['role' => $invitation->role]);
                    }
                    $user->currentTeam = $invitation->team;
                    $invitation->delete();
                } else {
                    // Normal login - use personal team
                    $user->currentTeam = $user->teams->firstWhere('personal_team', true);
                    if (! $user->currentTeam) {
                        $user->currentTeam = $user->recreate_personal_team();
                    }
                }
                session(['currentTeam' => $user->currentTeam]);

                return $user;
            }
        });
        Fortify::requestPasswordResetLinkView(function () {
            return view('auth.forgot-password');
        });
        Fortify::resetPasswordView(function ($request) {
            return view('auth.reset-password', ['request' => $request]);
        });
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        Fortify::confirmPasswordView(function () {
            return view('auth.confirm-password');
        });

        Fortify::twoFactorChallengeView(function () {
            return view('auth.two-factor-challenge');
        });

        RateLimiter::for('force-password-reset', function (Request $request) {
            return Limit::perMinute(15)->by($request->user()->id);
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            return Limit::perMinute(5)->by(self::rateLimitClientAddress($request));
        });

        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::username())));

            return Limit::perMinute(5)->by($email.'|'.self::rateLimitClientAddress($request));
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }

    private static function rateLimitClientAddress(Request $request): string
    {
        $remoteAddress = $request->server('REMOTE_ADDR');
        if (! is_string($remoteAddress) || trim($remoteAddress) === '') {
            throw new LogicException('Authentication rate limits require a server-supplied REMOTE_ADDR.');
        }

        if (self::isTrustedAuthenticationProxy($remoteAddress)) {
            $forwardedClientAddress = self::normalizeIpAddress((string) $request->ip());

            if ($forwardedClientAddress !== null) {
                return $forwardedClientAddress;
            }
        }

        return $remoteAddress;
    }

    private static function isTrustedAuthenticationProxy(string $remoteAddress): bool
    {
        if (in_array($remoteAddress, self::LOOPBACK_PROXY_ADDRESSES, true)) {
            return true;
        }

        if (! ControlPlaneMode::isActiveWebOnly()) {
            return false;
        }

        $normalizedRemoteAddress = self::normalizeIpAddress($remoteAddress);

        return $normalizedRemoteAddress !== null
            && in_array($normalizedRemoteAddress, self::trustedControlPlaneProxyAddresses(), true);
    }

    /**
     * @return list<string>
     */
    private static function trustedControlPlaneProxyAddresses(): array
    {
        $configuredAddresses = config('control-plane.trusted_proxy_addresses');

        if (! is_string($configuredAddresses) || $configuredAddresses === '') {
            return [];
        }

        $addresses = explode(',', $configuredAddresses);
        if (count($addresses) > self::MAX_CONTROL_PLANE_PROXY_ADDRESSES) {
            return [];
        }

        $normalizedAddresses = [];
        foreach ($addresses as $address) {
            $normalizedAddress = self::normalizeIpAddress($address);

            if ($address === ''
                || trim($address) !== $address
                || $normalizedAddress === null
                || $normalizedAddress !== $address
                || in_array($normalizedAddress, $normalizedAddresses, true)) {
                return [];
            }

            $normalizedAddresses[] = $normalizedAddress;
        }

        return $normalizedAddresses;
    }

    private static function normalizeIpAddress(string $address): ?string
    {
        $packedAddress = @inet_pton($address);
        if ($packedAddress === false) {
            return null;
        }

        $normalizedAddress = inet_ntop($packedAddress);

        return $normalizedAddress === false ? null : $normalizedAddress;
    }
}
