<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Laravel 12 bootstraps the app from bootstrap/app.php in the framework
     * TestCase. Re-export that here so project tests do not depend on a
     * missing CreatesApplication trait from older Laravel skeletons.
     */
    public function createApplication(): Application
    {
        return parent::createApplication();
    }
}
