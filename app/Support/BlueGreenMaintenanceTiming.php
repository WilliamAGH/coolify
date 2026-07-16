<?php

namespace App\Support;

final class BlueGreenMaintenanceTiming
{
    public const int STALE_AFTER_SECONDS = 300;

    public const int SCHEDULE_LOCK_EXPIRY_MINUTES = 6;
}
