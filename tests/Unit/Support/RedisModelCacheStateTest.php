<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit\Support;

use Sm_mE\RedisModelCache\Support\RedisModelCacheState;
use Sm_mE\RedisModelCache\Tests\TestCase;

class RedisModelCacheStateTest extends TestCase
{
    private RedisModelCacheState $state;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state = new RedisModelCacheState;
    }

    public function test_is_processing_returns_false_initially(): void
    {
        $this->assertFalse($this->state->isProcessing('App\Models\User', 1));
    }

    public function test_mark_processing_then_is_processing_returns_true(): void
    {
        $this->state->markProcessing('App\Models\User', 1);

        $this->assertTrue($this->state->isProcessing('App\Models\User', 1));
    }

    public function test_unmark_processing_reverts_is_processing(): void
    {
        $this->state->markProcessing('App\Models\User', 1);
        $this->state->unmarkProcessing('App\Models\User', 1);

        $this->assertFalse($this->state->isProcessing('App\Models\User', 1));
    }

    public function test_processing_tracks_multiple_model_classes_independently(): void
    {
        $this->state->markProcessing('App\Models\User', 1);
        $this->state->markProcessing('App\Models\Post', 42);

        $this->assertTrue($this->state->isProcessing('App\Models\User', 1));
        $this->assertFalse($this->state->isProcessing('App\Models\User', 2));
        $this->assertTrue($this->state->isProcessing('App\Models\Post', 42));
        $this->assertFalse($this->state->isProcessing('App\Models\Post', 1));
    }

    public function test_processing_tracks_multiple_ids_per_class(): void
    {
        $this->state->markProcessing('App\Models\User', 1);
        $this->state->markProcessing('App\Models\User', 2);
        $this->state->markProcessing('App\Models\User', 3);

        $this->assertTrue($this->state->isProcessing('App\Models\User', 1));
        $this->assertTrue($this->state->isProcessing('App\Models\User', 2));
        $this->assertTrue($this->state->isProcessing('App\Models\User', 3));
    }

    public function test_unmark_processing_only_removes_specific_id(): void
    {
        $this->state->markProcessing('App\Models\User', 1);
        $this->state->markProcessing('App\Models\User', 2);
        $this->state->unmarkProcessing('App\Models\User', 1);

        $this->assertFalse($this->state->isProcessing('App\Models\User', 1));
        $this->assertTrue($this->state->isProcessing('App\Models\User', 2));
    }

    public function test_unmark_processing_is_idempotent(): void
    {
        $this->state->unmarkProcessing('App\Models\User', 999);

        $this->assertFalse($this->state->isProcessing('App\Models\User', 999));
    }

    public function test_is_deleted_in_cycle_returns_false_initially(): void
    {
        $this->assertFalse($this->state->isDeletedInCycle('App\Models\User', 1));
    }

    public function test_mark_deleted_in_cycle_then_is_deleted_returns_true(): void
    {
        $this->state->markDeletedInCycle('App\Models\User', 1);

        $this->assertTrue($this->state->isDeletedInCycle('App\Models\User', 1));
    }

    public function test_flush_clears_all_state(): void
    {
        $this->state->markProcessing('App\Models\User', 1);
        $this->state->markDeletedInCycle('App\Models\User', 42);
        $this->state->flush();

        $this->assertFalse($this->state->isProcessing('App\Models\User', 1));
        $this->assertFalse($this->state->isDeletedInCycle('App\Models\User', 42));
    }

    public function test_state_isolation_between_instances(): void
    {
        $stateA = new RedisModelCacheState;
        $stateB = new RedisModelCacheState;

        $stateA->markProcessing('App\Models\User', 1);

        $this->assertTrue($stateA->isProcessing('App\Models\User', 1));
        $this->assertFalse($stateB->isProcessing('App\Models\User', 1));
    }

    public function test_processing_with_string_keys(): void
    {
        $this->state->markProcessing('App\Models\User', 'uuid-abc-123');

        $this->assertTrue($this->state->isProcessing('App\Models\User', 'uuid-abc-123'));
        $this->assertFalse($this->state->isProcessing('App\Models\User', 'uuid-xyz-789'));
    }
}
