<?php

namespace Tests\Feature;

use App\Models\{Branch, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CallCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_ticket_is_idempotent_by_external_call_id(): void
    {
        [$user, $branch] = $this->operator();
        $payload = [
            'phone' => '0599001122',
            'branch_id' => $branch->id,
            'external_call_id' => 'mock-call-stable-1',
        ];

        $first = $this->actingAs($user)->postJson('/api/call-center/tickets/manual', $payload)->assertOk();
        $second = $this->actingAs($user)->postJson('/api/call-center/tickets/manual', $payload)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('call_tickets', 1);
    }

    private function operator(): array
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        Permission::findOrCreate('access-call-center-interface', 'web');
        $user->givePermissionTo('access-call-center-interface');

        return [$user, $branch];
    }
}
