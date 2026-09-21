<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PayrollEmployee;
use App\Models\Role;
use App\Models\SalaryGrade;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * W5 — Salary grades.
 */
final class SalaryGradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_grade_can_be_created_and_assigned_to_an_employee(): void
    {
        $this->actAs('finance-manager');

        $grade = $this->postJson('/api/v1/payroll/grades', [
            'code' => 'g4', 'name' => 'Grade 4 — Manager', 'min_salary' => 15000, 'mid_salary' => 20000, 'max_salary' => 25000,
        ])->assertCreated()->json('data');
        $this->assertSame('G4', $grade['code']);

        $emp = $this->postJson('/api/v1/payroll/employees', [
            'employee_code' => 'E1', 'name' => 'Ahmed', 'is_saudi' => true, 'basic_salary' => 18000,
            'salary_grade_id' => $grade['id'], 'join_date' => '2024-01-01',
        ])->assertCreated()->json('data');

        $this->assertSame($grade['id'], PayrollEmployee::query()->find($emp['id'])->salary_grade_id);
        $this->assertTrue(SalaryGrade::query()->find($grade['id'])->contains(18000));
        $this->assertFalse(SalaryGrade::query()->find($grade['id'])->contains(30000));
    }

    public function test_max_below_min_is_rejected(): void
    {
        $this->actAs('finance-manager');

        $this->postJson('/api/v1/payroll/grades', [
            'code' => 'BAD', 'name' => 'Bad', 'min_salary' => 20000, 'mid_salary' => 15000, 'max_salary' => 10000,
        ])->assertStatus(422);
    }
}
