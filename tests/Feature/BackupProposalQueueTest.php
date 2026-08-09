<?php

namespace Tests\Feature;

use App\Livewire\BackupProposalQueue;
use App\Models\BackupPruneProposal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class BackupProposalQueueTest extends TestCase
{
    use RefreshDatabase;

    private string $backupRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupRoot = storage_path('app/backups');
        File::ensureDirectoryExists($this->backupRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupRoot);
        parent::tearDown();
    }

    private function pendingProposal(): BackupPruneProposal
    {
        $path = $this->backupRoot.DIRECTORY_SEPARATOR.'20260101_000000';
        File::ensureDirectoryExists($path);

        return BackupPruneProposal::create([
            'backup_path' => $path,
            'backup_created_at' => now()->subDays(20),
            'status' => 'pending',
        ]);
    }

    private function operator(): User
    {
        return User::factory()->create(['is_platform_operator' => true]);
    }

    public function test_operator_can_approve_and_the_directory_is_actually_deleted(): void
    {
        $proposal = $this->pendingProposal();
        $operator = $this->operator();

        Livewire::actingAs($operator)
            ->test(BackupProposalQueue::class)
            ->call('approve', $proposal->id);

        $fresh = $proposal->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($operator->id, $fresh->reviewed_by);
        $this->assertFalse(File::isDirectory($proposal->backup_path));
    }

    public function test_operator_can_reject_with_a_reason_and_the_directory_is_untouched(): void
    {
        $proposal = $this->pendingProposal();
        $operator = $this->operator();

        Livewire::actingAs($operator)
            ->test(BackupProposalQueue::class)
            ->call('promptReject', $proposal->id)
            ->set('rejectReason', 'Not ready, still needed for an audit.')
            ->call('confirmReject', $proposal->id);

        $fresh = $proposal->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Not ready, still needed for an audit.', $fresh->rejected_reason);
        $this->assertTrue(File::isDirectory($proposal->backup_path));
    }

    public function test_rejecting_without_a_reason_is_a_noop(): void
    {
        $proposal = $this->pendingProposal();
        $operator = $this->operator();

        Livewire::actingAs($operator)
            ->test(BackupProposalQueue::class)
            ->call('promptReject', $proposal->id)
            ->set('rejectReason', '')
            ->call('confirmReject', $proposal->id);

        $this->assertSame('pending', $proposal->fresh()->status);
    }

    public function test_acting_on_a_proposal_no_longer_pending_is_a_noop(): void
    {
        $proposal = $this->pendingProposal();
        $operator = $this->operator();
        $proposal->update(['status' => 'approved']); // already resolved by someone else

        Livewire::actingAs($operator)
            ->test(BackupProposalQueue::class)
            ->call('approve', $proposal->id);

        $this->assertTrue(File::isDirectory($proposal->backup_path), 'a no-op approve must not delete anything');
    }

    public function test_non_operator_is_blocked(): void
    {
        $this->pendingProposal();
        $regularUser = User::factory()->create(['is_platform_operator' => false]);

        $this->withoutExceptionHandling();
        $this->expectException(AuthorizationException::class);

        Livewire::actingAs($regularUser)->test(BackupProposalQueue::class);
    }

    public function test_operator_backups_route_requires_authentication(): void
    {
        $this->get('/operator/backups')->assertRedirect('/login');
    }
}
