<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CacheTypesTest extends TestCase
{
    public function test_query_results_and_dates_survive_a_real_cache_store(): void
    {
        config(['cache.default' => 'file']);
        Cache::forget('cache-types-test');

        $value = ['rows' => collect([(object) ['id' => 1]]), 'at' => now()];
        Cache::put('cache-types-test', $value, 60);
        $back = Cache::get('cache-types-test');

        $this->assertSame(1, $back['rows']->first()->id);
        $this->assertTrue($back['at']->equalTo($value['at']));
        Cache::forget('cache-types-test');
    }

    public function test_models_are_still_refused(): void
    {
        config(['cache.default' => 'file']);
        Cache::put('cache-types-model', new \App\Models\User(['name' => 'x']), 60);

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, Cache::get('cache-types-model'));
        Cache::forget('cache-types-model');
    }
}
