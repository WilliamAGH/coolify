<?php

use App\Actions\Application\BlueGreen\BlueGreenTopologyLock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

it('requires an active database transaction', function () {
    expect(fn () => BlueGreenTopologyLock::acquire())
        ->toThrow(RuntimeException::class, 'requires an active database transaction');
});

it('acquires the topology lock inside the testing transaction', function () {
    DB::transaction(function (): void {
        BlueGreenTopologyLock::acquire();
    });
})->throwsNoExceptions();
