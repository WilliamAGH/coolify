<?php

namespace App\Actions\Application\BlueGreen;

enum BlueGreenProxyEvictionState: string
{
    case Tombstone = 'tombstone';
    case Absent = 'absent';
}
