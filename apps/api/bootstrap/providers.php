<?php

use App\Providers\AppServiceProvider;
use App\Providers\CommerceServiceProvider;
use App\Providers\PlatformServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    // Registered after the platform it is built on, and separately from it:
    // the platform must not know which verticals exist.
    CommerceServiceProvider::class,
];
