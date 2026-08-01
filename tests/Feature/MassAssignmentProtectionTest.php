<?php

use App\Http\Controllers\Api\ApplicationsController;
use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;

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

    test('Application model keeps its primary key guarded', function () {
        $application = new Application;

        expect($application->isFillable('id'))->toBeFalse('Application id should not be fillable');
    });

    test('Application API create and update paths only mass assign whitelisted request fields', function () {
        $controller = new ReflectionClass(ApplicationsController::class);
        $applicationSettingFields = $controller->getConstant('APPLICATION_SETTING_FIELDS');
        $dangerousFields = ['id', 'uuid', 'team_id', 'environment_id', 'destination_id'];

        expect($applicationSettingFields)->toBeArray()
            ->and(array_intersect($dangerousFields, $applicationSettingFields))->toBeEmpty();

        // Relationship keys and uuid must remain fillable for trusted internal create and clone flows.
        // API protection therefore lives at the controller boundary: unknown request fields are rejected,
        // and only the explicit whitelist reaches Application::fill().
        foreach (['create_application', 'update_by_uuid'] as $methodName) {
            $method = $controller->getMethod($methodName);
            $fileName = $method->getFileName();
            $lines = file($fileName);
            $methodSource = implode('', array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));

            $matched = preg_match('/\$allowedFields\s*=\s*\[(.*?)\];/s', $methodSource, $matches);
            expect($matched)->toBe(1, "{$methodName} must define an explicit allowed-fields whitelist");

            preg_match_all("/'([^']+)'/", $matches[1], $fieldMatches);
            $allowedFields = array_unique([...$fieldMatches[1], ...$applicationSettingFields]);

            expect(array_intersect($dangerousFields, $allowedFields))
                ->toBeEmpty("{$methodName} must not accept internal IDs from raw request input")
                ->and($methodSource)
                ->toContain('$extraFields = array_diff(array_keys($request->all()), $allowedFields);')
                ->toContain("'This field is not allowed.'")
                ->toContain('$request->only($allowedFields)')
                ->not->toContain('$application->fill($request->all())');
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

    test('User model blocks mass assignment of auth-sensitive fields', function () {
        $user = new User;

        expect($user->isFillable('id'))->toBeFalse('User id should not be fillable');
        expect($user->isFillable('email_verified_at'))->toBeFalse('email_verified_at should not be fillable');
        expect($user->isFillable('remember_token'))->toBeFalse('remember_token should not be fillable');
        expect($user->isFillable('two_factor_secret'))->toBeFalse('two_factor_secret should not be fillable');
        expect($user->isFillable('two_factor_recovery_codes'))->toBeFalse('two_factor_recovery_codes should not be fillable');
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

    test('standalone database models keep their primary keys guarded', function () {
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
            expect($model->isFillable('id'))
                ->toBeFalse("Model {$modelClass} should not allow mass assignment of 'id'");
        }
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

    test('Application fill ignores non-fillable ownership fields', function () {
        $application = new Application;
        $application->fill([
            'name' => 'test-app',
            'team_id' => 999,
        ]);

        expect($application->name)->toBe('test-app');
        expect($application->getAttribute('team_id'))->toBeNull();
    });

    test('Service model keeps its primary key guarded', function () {
        $service = new Service;

        expect($service->isFillable('id'))->toBeFalse();
    });
});
