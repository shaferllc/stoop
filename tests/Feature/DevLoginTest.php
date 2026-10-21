<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DevLoginTest extends TestCase
{
    public function test_the_quick_login_only_exists_in_local_development(): void
    {
        // Tests run as "testing", like production would: the route isn't registered at all.
        $this->assertFalse(Route::has('dev.login'));
        $this->get('/dev/login/1')->assertNotFound();
        $this->get('/login')->assertDontSee('Quick login');
    }
}
