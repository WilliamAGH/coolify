<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneAuthenticationProxyProof;
use App\Models\OauthSetting;
use App\Models\TeamInvitation;
use App\Models\User;
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
            if (! $user || ! Hash::check($request->password, $user->password)) {
                return null;
            }

            $user->load('teams');
            $user->updated_at = now();
            $user->save();

            // Check if user has a pending invitation they haven't accepted yet
            $invitation = TeamInvitation::whereEmail($email)->first();
            if ($invitation && $invitation->isValid()) {
                // User is logging in for the first time after being invited
                // Attach them to the invited team if not already attached
                if (! $user->teams()->where('team_id', $invitation->team->id)->exists()) {
                    $invitation->team->attachMember($user, $invitation->role);
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
            $realIp = self::rateLimitClientAddress($request);

            $limits = [
                Limit::perMinutes(10, 3)->by('forgot-password:ip:'.sha1($realIp)),
            ];

            $emailIdentity = normalize_email_identity($request->input('email'));
            if ($emailIdentity !== null) {
                $limits[] = Limit::perHour(3)->by('forgot-password:email-identity:'.sha1($emailIdentity));
            }

            return $limits;
        });

        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::username())));
            $realIp = self::rateLimitClientAddress($request);

            return Limit::perMinute(5)->by($email.'|'.$realIp);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }

    private static function rateLimitClientAddress(Request $request): string
    {
        $remoteAddress = self::normalizeIpAddress($request->server('REMOTE_ADDR'));
        if ($remoteAddress === null) {
            throw new LogicException('Authentication rate limits require a canonical server-supplied REMOTE_ADDR.');
        }

        $isLoopbackProxy = in_array($remoteAddress, self::LOOPBACK_PROXY_ADDRESSES, true);
        $hasControlPlaneProof = (new VerifyControlPlaneAuthenticationProxyProof)->handle($request);
        if ($isLoopbackProxy || $hasControlPlaneProof) {
            return self::normalizeIpAddress($request->ip()) ?? $remoteAddress;
        }

        return $remoteAddress;
    }

    private static function normalizeIpAddress(mixed $address): ?string
    {
        if (! is_string($address) || trim($address) !== $address || $address === '') {
            return null;
        }

        $packedAddress = @inet_pton($address);
        if ($packedAddress === false) {
            return null;
        }

        $normalizedAddress = inet_ntop($packedAddress);

        return $normalizedAddress === false ? null : $normalizedAddress;
    }
}
