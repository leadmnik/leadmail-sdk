<?php

namespace LeadM\LeadMail\Tests;

use LeadM\LeadMail\LeadMailServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LeadMailServiceProvider::class];
    }
}
