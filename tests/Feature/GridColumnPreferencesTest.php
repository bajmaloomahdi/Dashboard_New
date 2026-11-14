<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GridColumnPreferencesTest extends TestCase
{
    private const URL = '/grid-column-preferences/admin.users.list';

    private function signIn(): void
    {
        $user = new User;
        $user->UserID = 41;
        $this->actingAs($user);
    }

    public function test_read_and_save_use_only_the_authenticated_owner(): void
    {
        $this->signIn();
        DB::shouldReceive('selectOne')->once()
            ->with('EXEC dbo.sp_UserGridColumnPreferences_Get ?, ?', [41, 'admin.users.list'])
            ->andReturnNull();
        $this->getJson(self::URL.'?UserID=99')->assertOk()->assertJsonPath('version', null);

        DB::shouldReceive('selectOne')->once()
            ->with('DECLARE @v binary(8) = CONVERT(binary(8), ?, 2); EXEC dbo.sp_UserGridColumnPreferences_Save ?, ?, ?, 1, @v;',
                [null, 41, 'admin.users.list', '{"contact":false}'])
            ->andReturn((object) ['VisibilityJson' => '{"contact":false}', 'VersionToken' => '0000000000000001']);
        $this->putJson(self::URL, ['UserID' => 99, 'visibility' => ['contact' => false], 'version' => null])
            ->assertOk()->assertJsonPath('visibility.contact', false)->assertJsonPath('version', '0000000000000001');
    }

    public function test_rejects_required_or_unknown_columns_before_calling_sql(): void
    {
        $this->signIn();
        DB::shouldReceive('selectOne')->never();
        foreach (['user', 'unknown'] as $key) {
            $this->putJson(self::URL, ['visibility' => [$key => false], 'version' => null])->assertUnprocessable();
        }
    }

    public function test_reset_uses_the_session_owner_and_expected_version(): void
    {
        $this->signIn();
        DB::shouldReceive('selectOne')->once()
            ->with('DECLARE @v binary(8) = CONVERT(binary(8), ?, 2); EXEC dbo.sp_UserGridColumnPreferences_Reset ?, ?, @v;',
                ['0000000000000001', 41, 'admin.users.list'])->andReturn((object) ['Reset' => true]);
        $this->deleteJson(self::URL, ['UserID' => 99, 'version' => '0000000000000001'])
            ->assertOk()->assertJsonPath('version', null);
    }

    public function test_guest_cannot_access_preferences(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_workflow_grid_keeps_its_view_permission(): void
    {
        $this->signIn();
        DB::shouldReceive('select')->once()
            ->with('EXEC sp_GetUserPermissions @UserID = ?', [41])->andReturn([]);
        DB::shouldReceive('selectOne')->never();
        $this->getJson('/grid-column-preferences/workflow.instances')->assertForbidden();
    }

    public function test_role_permissions_tables_have_separate_preferences(): void
    {
        $this->signIn();
        foreach (['menus', 'reports', 'permissions'] as $tab) {
            DB::shouldReceive('selectOne')->once()
                ->with('EXEC dbo.sp_UserGridColumnPreferences_Get ?, ?', [41, 'admin.roles.'.$tab])
                ->andReturnNull();
            $this->getJson('/grid-column-preferences/admin.roles.'.$tab)
                ->assertOk()->assertJsonPath('required', ['title', 'access']);
        }
    }
}
