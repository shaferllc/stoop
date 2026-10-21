<?php

namespace Tests\Feature;

use App\Support\Ai;
use App\Support\Claude;
use App\Support\ClaudeText;
use App\Support\ModerationAssist;
use App\Support\Translator;
use Tests\TestCase;

class AiLimitsTest extends TestCase
{
    public function test_only_moderation_can_use_ai_by_default(): void
    {
        config(['stoop.ai_features' => ['moderation'], 'services.anthropic.key' => 'sk-test']);

        $this->assertTrue(Ai::allows('moderation'));
        $this->assertSame('sk-test', Ai::key('moderation'));
        $this->assertNull(Ai::key('content'));
        $this->assertTrue(ModerationAssist::enabled());
        $this->assertFalse(Claude::enabled());
        $this->assertFalse(ClaudeText::enabled());
        $this->assertTrue(ClaudeText::enabled('moderation'));
        $this->assertFalse(Translator::enabled());
    }
}
