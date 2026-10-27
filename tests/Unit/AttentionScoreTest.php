<?php

namespace Tests\Unit;

use App\Enums\AttentionType;
use App\Services\Attention\AttentionScore;
use PHPUnit\Framework\TestCase;

class AttentionScoreTest extends TestCase
{
    public function test_a_perfect_instant_round_earns_the_whole_performance(): void
    {
        $this->assertSame(800, AttentionScore::performance(AttentionType::Selective, 100, true, 0, null));
        $this->assertSame(1000, AttentionScore::ROUND_MAX);
        $this->assertSame(5000, AttentionScore::gameMax());
    }

    public function test_the_speed_bonus_shrinks_with_time_and_needs_a_correct_round(): void
    {
        // Half of the 20 s window left: half of the 300 bonus.
        $this->assertSame(650, AttentionScore::performance(AttentionType::Selective, 100, true, 10_000, null));
        $this->assertSame(500, AttentionScore::performance(AttentionType::Selective, 100, true, 25_000, null));
        $this->assertSame(300, AttentionScore::performance(AttentionType::Selective, 60, false, 1_000, null));
    }

    public function test_the_go_no_go_challenge_scores_the_reaction_time(): void
    {
        // 400 ms of an 800 ms window, whatever the total duration.
        $this->assertSame(610, AttentionScore::performance(AttentionType::Alternating, 92, true, 30_000, 400));
        $this->assertSame(460, AttentionScore::performance(AttentionType::Alternating, 92, true, 30_000, null));
    }

    public function test_naming_the_kind_of_attention_is_worth_a_bonus(): void
    {
        $this->assertSame(200, AttentionScore::concept(true));
        $this->assertSame(0, AttentionScore::concept(false));
    }
}
