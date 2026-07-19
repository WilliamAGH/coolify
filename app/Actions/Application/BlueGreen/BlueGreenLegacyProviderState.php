<?php

namespace App\Actions\Application\BlueGreen;

enum BlueGreenLegacyProviderState
{
    case Active;
    case Evicted;
}
