<?php

namespace App\Actions\Application\BlueGreen;

enum BlueGreenProxyTombstoneInstallation: string
{
    case TombstonePresent = 'tombstone-present';
    case AlreadyAbsent = 'already-absent';
}
