<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['email' => 'invited@example.com']);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    // The auto-created personal team has show_boarding=true, and the boarding
    // middleware redirects everything but onboarding paths; these tests are
    // about the invitation flow, not boarding
    $this->user->teams()->first()?->update(['show_boarding' => false]);

    $this->invitation = TeamInvitation::create([
        'team_id' => $this->team->id,
        'uuid' => 'test-invitation-uuid',
        'email' => 'invited@example.com',
        'role' => 'member',
        'link' => url('/invitations/test-invitation-uuid'),
        'via' => 'link',
    ]);
});

test('GET invitation shows landing page without accepting', function () {
    $this->actingAs($this->user);

    $response = $this->get('/invitations/test-invitation-uuid');

    $response->assertStatus(200);
    $response->assertViewIs('invitation.accept');
    $response->assertSee($this->team->name);
    $response->assertSee('Accept Invitation');

    // Invitation should NOT be deleted (not accepted yet)
    $this->assertDatabaseHas('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);

    // User should NOT be added to the team
    expect($this->user->teams()->where('team_id', $this->team->id)->exists())->toBeFalse();
});

test('GET invitation with reset-password query param does not reset password', function () {
    $this->actingAs($this->user);
    $originalPassword = $this->user->password;

    $response = $this->get('/invitations/test-invitation-uuid?reset-password=1');

    $response->assertStatus(200);

    // Password should NOT be changed
    $this->user->refresh();
    expect($this->user->password)->toBe($originalPassword);

    // Invitation should NOT be accepted
    $this->assertDatabaseHas('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);
});

test('POST invitation accepts and adds user to team', function () {
    $this->actingAs($this->user);

    $response = $this->post('/invitations/test-invitation-uuid');

    $response->assertRedirect(route('team.index'));

    // Invitation should be deleted
    $this->assertDatabaseMissing('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);

    // User should be added to the team
    expect($this->user->teams()->where('team_id', $this->team->id)->exists())->toBeTrue();
});

test('POST invitation without CSRF token is rejected', function () {
    // Laravel's VerifyCsrfToken skips verification entirely under
    // runningUnitTests(), so exercise the middleware's token comparison directly
    $middleware = new VerifyCsrfToken(app(), app('encrypter'));
    $request = Request::create('/invitations/test-invitation-uuid', 'POST');
    $request->setLaravelSession(app('session')->driver());
    $request->headers->set('X-CSRF-TOKEN', 'invalid-token');

    $tokensMatch = new ReflectionMethod($middleware, 'tokensMatch');

    expect($tokensMatch->invoke($middleware, $request))->toBeFalse();
});

test('unauthenticated user cannot view invitation', function () {
    $response = $this->get('/invitations/test-invitation-uuid');

    $response->assertRedirect();
});

test('wrong user cannot view invitation', function () {
    $otherUser = User::factory()->create(['email' => 'other@example.com']);
    $otherUser->teams()->first()?->update(['show_boarding' => false]);
    $this->actingAs($otherUser);

    $response = $this->get('/invitations/test-invitation-uuid');

    $response->assertStatus(400);
});

test('wrong user cannot accept invitation via POST', function () {
    $otherUser = User::factory()->create(['email' => 'other@example.com']);
    $otherUser->teams()->first()?->update(['show_boarding' => false]);
    $this->actingAs($otherUser);

    $response = $this->post('/invitations/test-invitation-uuid');

    $response->assertStatus(400);

    // Invitation should still exist
    $this->assertDatabaseHas('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);
});

test('GET revoke route no longer exists', function () {
    $this->actingAs($this->user);

    // No dedicated revoke route exists; unmatched paths hit the
    // Route::any('/{any}') catch-all, which redirects authenticated users home
    $response = $this->get('/invitations/test-invitation-uuid/revoke');

    $response->assertRedirect(RouteServiceProvider::HOME);

    // The invitation must be untouched
    $this->assertDatabaseHas('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);
});

test('POST invitation for already-member user deletes invitation without duplicating', function () {
    $this->user->teams()->attach($this->team->id, ['role' => 'member']);
    $this->actingAs($this->user);

    $response = $this->post('/invitations/test-invitation-uuid');

    $response->assertRedirect(route('team.index'));

    // Invitation should be deleted
    $this->assertDatabaseMissing('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);

    // User should still have exactly one membership in this team
    expect($this->user->teams()->where('team_id', $this->team->id)->count())->toBe(1);
});
