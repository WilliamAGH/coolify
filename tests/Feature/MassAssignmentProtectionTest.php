<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Model::reguard();
});

afterEach(function () {
    Model::reguard();
});

describe('mass assignment protection', function () {

    test('no API-exposed model uses unguarded $guarded = []', function () {
        $models = [
            Application::class,
            Service::class,
            User::class,
            Team::class,
            Server::class,
            StandalonePostgresql::class,
            StandaloneRedis::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneMongodb::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
            StandaloneClickhouse::class,
        ];

        foreach ($models as $modelClass) {
            $model = new $modelClass;
            $guarded = $model->getGuarded();
            $fillable = $model->getFillable();

            // Model must NOT have $guarded = [] (empty guard = no protection)
            // It should either have non-empty $guarded OR non-empty $fillable
            $hasProtection = $guarded !== ['*'] ? count($guarded) > 0 : true;
            $hasProtection = $hasProtection || count($fillable) > 0;

            expect($hasProtection)
                ->toBeTrue("Model {$modelClass} has no mass assignment protection (empty \$guarded and empty \$fillable)");
        }
    });

    test('Application model allows mass assignment of user-facing fields', function () {
        $application = new Application;
        $userFields = ['name', 'description', 'git_repository', 'git_branch', 'build_pack', 'install_command', 'build_command', 'start_command', 'ports_exposes', 'health_check_path', 'limits_memory', 'status'];

        foreach ($userFields as $field) {
            expect($application->isFillable($field))
                ->toBeTrue("Application model should allow mass assignment of '{$field}'");
        }
    });

    test('Server model has $fillable and no conflicting $guarded', function () {
        $server = new Server;
        $fillable = $server->getFillable();
        $guarded = $server->getGuarded();

        expect($fillable)->not->toBeEmpty('Server model should have explicit $fillable');

        // Guarded should be the default ['*'] when $fillable is set, not []
        expect($guarded)->not->toBe([], 'Server model should not have $guarded = [] overriding $fillable');
    });

    test('Server model blocks mass assignment of dangerous fields', function () {
        $server = new Server;

        // These fields should not be mass-assignable via the API
        expect($server->isFillable('id'))->toBeFalse();
        expect($server->isFillable('uuid'))->toBeFalse();
        expect($server->isFillable('created_at'))->toBeFalse();
    });

    test('User model allows mass assignment of profile fields', function () {
        $user = new User;

        expect($user->isFillable('name'))->toBeTrue();
        expect($user->isFillable('email'))->toBeTrue();
        expect($user->isFillable('password'))->toBeTrue();
    });

    test('Team model blocks mass assignment of internal fields', function () {
        $team = new Team;

        expect($team->isFillable('id'))->toBeFalse();
        expect($team->isFillable('use_instance_email_settings'))->toBeFalse('use_instance_email_settings should not be fillable (migrated to EmailNotificationSettings)');
        expect($team->isFillable('resend_api_key'))->toBeFalse('resend_api_key should not be fillable (migrated to EmailNotificationSettings)');
    });

    test('Team model allows mass assignment of expected fields', function () {
        $team = new Team;

        expect($team->isFillable('name'))->toBeTrue();
        expect($team->isFillable('description'))->toBeTrue();
        expect($team->isFillable('personal_team'))->toBeTrue();
        expect($team->isFillable('show_boarding'))->toBeTrue();
        expect($team->isFillable('custom_server_limit'))->toBeTrue();
    });

    test('standalone database models allow mass assignment of config fields', function () {
        $model = new StandalonePostgresql;
        expect($model->isFillable('name'))->toBeTrue();
        expect($model->isFillable('postgres_user'))->toBeTrue();
        expect($model->isFillable('postgres_password'))->toBeTrue();
        expect($model->isFillable('image'))->toBeTrue();
        expect($model->isFillable('limits_memory'))->toBeTrue();

        $model = new StandaloneRedis;
        expect($model->isFillable('redis_conf'))->toBeTrue();

        $model = new StandaloneMysql;
        expect($model->isFillable('mysql_root_password'))->toBeTrue();

        $model = new StandaloneMongodb;
        expect($model->isFillable('mongo_initdb_root_username'))->toBeTrue();
    });

    test('standalone database models allow mass assignment of public_port_timeout', function () {
        $models = [
            StandalonePostgresql::class,
            StandaloneRedis::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneMongodb::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
            StandaloneClickhouse::class,
        ];

        foreach ($models as $modelClass) {
            $model = new $modelClass;
            expect($model->isFillable('public_port_timeout'))
                ->toBeTrue("{$modelClass} should allow mass assignment of 'public_port_timeout'");
        }
    });

    test('standalone database models allow mass assignment of SSL fields where applicable', function () {
        $sslModels = [
            StandalonePostgresql::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneMongodb::class,
            StandaloneRedis::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
        ];

        foreach ($sslModels as $modelClass) {
            $model = new $modelClass;
            expect($model->isFillable('enable_ssl'))
                ->toBeTrue("{$modelClass} should allow mass assignment of 'enable_ssl'");
        }

        // Clickhouse has no SSL columns
        expect((new StandaloneClickhouse)->isFillable('enable_ssl'))->toBeFalse();

        $sslModeModels = [
            StandalonePostgresql::class,
            StandaloneMysql::class,
            StandaloneMongodb::class,
        ];

        foreach ($sslModeModels as $modelClass) {
            $model = new $modelClass;
            expect($model->isFillable('ssl_mode'))
                ->toBeTrue("{$modelClass} should allow mass assignment of 'ssl_mode'");
        }
    });

    describe('untrusted request boundaries', function () {
        beforeEach(function () {
            InstanceSettings::forceCreate([
                'id' => 0,
                'is_api_enabled' => true,
                'is_registration_enabled' => true,
            ]);

            $this->team = Team::factory()->create();
            $this->user = User::factory()->create();
            $this->team->members()->attach($this->user->id, ['role' => 'owner']);
            session(['currentTeam' => $this->team]);

            $this->bearerToken = $this->user->createToken('mass-assignment-boundary', ['*'])->plainTextToken;
            $this->apiHeaders = [
                'Authorization' => 'Bearer '.$this->bearerToken,
                'Content-Type' => 'application/json',
            ];

            $this->server = Server::factory()->create(['team_id' => $this->team->id]);
            $this->destination = StandaloneDocker::query()
                ->where('server_id', $this->server->id)
                ->firstOrFail();
            $this->project = Project::factory()->create(['team_id' => $this->team->id]);
            $this->environment = $this->project->environments()->firstOrFail();

            $this->application = Application::factory()->create([
                'environment_id' => $this->environment->id,
                'destination_id' => $this->destination->id,
                'destination_type' => $this->destination->getMorphClass(),
            ]);
            $this->service = Service::factory()->create([
                'environment_id' => $this->environment->id,
                'server_id' => $this->server->id,
                'destination_id' => $this->destination->id,
                'destination_type' => $this->destination->getMorphClass(),
            ]);
            $this->database = StandalonePostgresql::create([
                'name' => 'boundary-test-postgres',
                'image' => 'postgres:16-alpine',
                'postgres_user' => 'postgres',
                'postgres_password' => 'password',
                'postgres_db' => 'postgres',
                'environment_id' => $this->environment->id,
                'destination_id' => $this->destination->id,
                'destination_type' => $this->destination->getMorphClass(),
            ]);
        });

        test('application API rejects identity and relationship rebinding fields', function () {
            $originalRelationships = $this->application->only([
                'uuid',
                'environment_id',
                'destination_id',
                'destination_type',
                'source_id',
                'source_type',
                'private_key_id',
                'repository_project_id',
            ]);
            $payload = [
                'id' => 999999,
                'uuid' => $this->application->uuid,
                'environment_id' => 999999,
                'destination_id' => 999999,
                'destination_type' => StandaloneDocker::class,
                'source_id' => 999999,
                'source_type' => User::class,
                'private_key_id' => 999999,
                'repository_project_id' => 999999,
            ];

            $this->withHeaders($this->apiHeaders)
                ->patchJson("/api/v1/applications/{$this->application->uuid}", $payload)
                ->assertUnprocessable()
                ->assertInvalid(array_keys($payload));

            expect($this->application->refresh()->only(array_keys($originalRelationships)))
                ->toBe($originalRelationships);
        });

        test('service API rejects identity and relationship rebinding fields', function () {
            $originalRelationships = $this->service->only([
                'uuid',
                'environment_id',
                'server_id',
                'destination_id',
                'destination_type',
            ]);
            $payload = [
                'id' => 999999,
                'uuid' => $this->service->uuid,
                'environment_id' => 999999,
                'server_id' => 999999,
                'destination_id' => 999999,
                'destination_type' => StandaloneDocker::class,
            ];

            $this->withHeaders($this->apiHeaders)
                ->patchJson("/api/v1/services/{$this->service->uuid}", $payload)
                ->assertUnprocessable()
                ->assertInvalid(array_keys($payload));

            expect($this->service->refresh()->only(array_keys($originalRelationships)))
                ->toBe($originalRelationships);
        });

        test('database API rejects identity and relationship rebinding fields', function () {
            $originalRelationships = $this->database->only([
                'uuid',
                'environment_id',
                'destination_id',
                'destination_type',
            ]);
            $payload = [
                'id' => 999999,
                'uuid' => $this->database->uuid,
                'environment_id' => 999999,
                'destination_id' => 999999,
                'destination_type' => StandaloneDocker::class,
            ];

            $this->withHeaders($this->apiHeaders)
                ->patchJson("/api/v1/databases/{$this->database->uuid}", $payload)
                ->assertUnprocessable()
                ->assertInvalid(array_keys($payload));

            expect($this->database->refresh()->only(array_keys($originalRelationships)))
                ->toBe($originalRelationships);
        });

        test('registration ignores injected authentication and email-change state', function () {
            $email = 'boundary-registration@example.com';

            $this->post('/register', [
                'name' => 'Boundary Registration',
                'email' => $email,
                'password' => 'SecurePassword123!',
                'password_confirmation' => 'SecurePassword123!',
                'id' => 999999,
                'force_password_reset' => true,
                'remember_token' => 'attacker-controlled-token',
                'two_factor_secret' => 'attacker-controlled-secret',
                'two_factor_recovery_codes' => '["attacker-controlled-code"]',
                'pending_email' => 'attacker-pending@example.com',
                'email_change_code' => '000000',
                'email_change_code_expires_at' => '2999-01-01 00:00:00',
            ])->assertRedirect();

            $registeredUser = User::query()->where('email', $email)->firstOrFail();

            expect($registeredUser->id)->not->toBe(999999)
                ->and($registeredUser->force_password_reset)->toBeFalse()
                ->and($registeredUser->remember_token)->toBeNull()
                ->and($registeredUser->two_factor_secret)->toBeNull()
                ->and($registeredUser->two_factor_recovery_codes)->toBeNull()
                ->and($registeredUser->pending_email)->toBeNull()
                ->and($registeredUser->email_change_code)->toBeNull()
                ->and($registeredUser->email_change_code_expires_at)->toBeNull();
        });
    });
});
