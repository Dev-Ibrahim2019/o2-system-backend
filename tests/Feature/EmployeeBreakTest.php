<?php
namespace Tests\Feature;
use App\Models\{Branch,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class EmployeeBreakTest extends TestCase {
 use RefreshDatabase;
 public function test_break_is_persisted_and_duplicate_is_blocked():void {
  $branch=Branch::factory()->create();$user=User::factory()->create(['branch_id'=>$branch->id]);Permission::findOrCreate('access-call-center-interface','web');$user->givePermissionTo('access-call-center-interface');
  $first=$this->actingAs($user)->postJson('/api/call-center/agent/breaks',['break_type'=>'regular'])->assertCreated();
  $this->actingAs($user)->postJson('/api/call-center/agent/breaks',['break_type'=>'regular'])->assertUnprocessable();
  $this->actingAs($user)->getJson('/api/call-center/agent/breaks/today')->assertOk()->assertJsonPath('data.breaks_count',1);
  $this->actingAs($user)->postJson('/api/call-center/agent/breaks/'.$first->json('data.id').'/end')->assertOk();
  $this->assertDatabaseHas('employee_break_sessions',['user_id'=>$user->id,'status'=>'ended']);
 }
}
