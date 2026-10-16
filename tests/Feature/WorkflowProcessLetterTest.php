<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\Exceptions\WorkflowStateException;
use App\Services\Workflow\LetterTemplateService;
use App\Services\Workflow\Support\WorkflowStore;
use App\Services\Workflow\TaskService;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowHistoryRecorder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «نامهٔ فرایندی» — Workflow Start Context + POST /workflow/letters (Pre-create + Adopt).
 *
 * تضمین‌ها: گیرنده = Assignmentِ واقعیِ Stepِ اولِ همان Definition (نه Hard-code)؛ Submit همه‌چیز را
 * در سرور دوباره حساب می‌کند؛ نامه یک Message «وظیفه» است؛ در هر خطا هیچ Message/Instance/Notificationی
 * باقی نمی‌ماند؛ و محدودیت‌های CONDITION / N_OF_M فقط مسیرِ نامه را می‌بندند.
 *
 * دادهٔ واقعی: کاربر ۲ (WORKFLOW_* کامل) مدیرِ مستقیمش کاربر ۴ است؛ کاربر ۱۴ برایِ چندگیرنده/رونوشت.
 */
class WorkflowProcessLetterTest extends TestCase
{
    use DatabaseTransactions;

    private const STARTER = 2;
    private const MANAGER = 4;
    private const OTHER = 14;

    private WorkflowDefinitionService $defs;
    private LetterTemplateService $templates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defs = $this->app->make(WorkflowDefinitionService::class);
        $this->templates = $this->app->make(LetterTemplateService::class);
    }

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    /** START → REVIEW(APPROVAL, assignments) → END. */
    private function publish(array $assignments, string $policy = 'ANY', ?int $required = null, string $entityType = 'MESSAGE', bool $conditionFirst = false): int
    {
        $code = 'LTR_' . strtoupper(bin2hex(random_bytes(4)));
        $def = $this->defs->save(['latinName' => $code, 'name' => 'نامهٔ فرایندی ' . $code, 'entityType' => $entityType], self::STARTER);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::STARTER);

        $steps = [['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0]];
        $transitions = [];
        if ($conditionFirst) {
            $steps[] = ['code' => 'COND', 'name' => 'شرط', 'stepType' => 'CONDITION', 'sortOrder' => 1];
            $transitions[] = ['code' => 'T0', 'fromStepCode' => 'START', 'toStepCode' => 'COND', 'isDefault' => true];
            $transitions[] = ['code' => 'T1', 'fromStepCode' => 'COND', 'toStepCode' => 'REVIEW', 'isDefault' => true];
        } else {
            $transitions[] = ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW', 'isDefault' => true];
        }
        $steps[] = [
            'code' => 'REVIEW', 'name' => 'بررسی', 'stepType' => 'APPROVAL', 'assignPolicy' => $policy,
            'requiredApprovals' => $required, 'allowDelegation' => false, 'sortOrder' => 2,
        ];
        $steps[] = ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 3];
        $transitions[] = ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'];

        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => $steps,
            'actions' => [['stepCode' => 'REVIEW', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0]],
            'assignments' => array_map(fn ($a) => ['stepCode' => 'REVIEW'] + $a, $assignments),
            'transitions' => $transitions,
        ], self::STARTER);
        $this->defs->publish((int) $ver->VersionID, self::STARTER);

        return $definitionId;
    }

    private function template(int $definitionId): int
    {
        $res = $this->templates->save([
            'code' => 'LT_' . strtoupper(bin2hex(random_bytes(4))),
            'name' => 'قالبِ نامهٔ فرایندی',
            'entityType' => 'MESSAGE',
            'definitionId' => $definitionId,
            'subjectTemplate' => 'درخواست {{USER_FULL_NAME}}',
            'bodyTemplate' => 'از تاریخ {{FROM_DATE}} تا {{TO_DATE}} — ثبت در {{TODAY}}',
        ], self::STARTER);

        return (int) $res->LetterTemplateID;
    }

    private function payload(int $definitionId, int $templateId, array $extra = []): array
    {
        return array_merge([
            'definitionId' => $definitionId,
            'letterTemplateId' => $templateId,
            'formValues' => ['FROM_DATE' => '2026-10-01', 'TO_DATE' => '2026-10-03'],
            'msgPriorityID' => 1,
        ], $extra);
    }

    /** @return array{messages:int, instances:int, notifications:int, stepInstances:int} */
    private function counts(): array
    {
        return [
            'messages' => (int) DB::selectOne('SELECT COUNT(*) c FROM Messages')->c,
            'instances' => (int) DB::selectOne('SELECT COUNT(*) c FROM WorkflowInstances')->c,
            'notifications' => (int) DB::selectOne('SELECT COUNT(*) c FROM UserNotifications')->c,
            'stepInstances' => (int) DB::selectOne('SELECT COUNT(*) c FROM WorkflowStepInstances')->c,
        ];
    }

    private function assertNothingWritten(array $before): void
    {
        $this->assertSame($before, $this->counts(), 'هیچ Message/Instance/StepInstance/Notificationای نباید ساخته شده باشد.');
    }

    private function spSupportsCopy(): bool
    {
        return (bool) DB::selectOne(
            "SELECT 1 AS x FROM sys.parameters WHERE object_id = OBJECT_ID('dbo.sp_Wf_CreateTaskMessage') AND name = '@CopyUserIDs'"
        );
    }

    /* ============================ Start Context ============================ */

    public function test_context_shows_starter_first_step_and_real_recipients(): void
    {
        $defId = $this->publish([['assigneeType' => 'DIRECT_MANAGER']]);

        $res = $this->as(self::STARTER)->getJson("/workflow/definitions/{$defId}/preview-assignees");

        $res->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('startable', true)
            ->assertJsonPath('resolved', true)
            ->assertJsonPath('starter.userId', self::STARTER)
            ->assertJsonPath('step.code', 'REVIEW')
            ->assertJsonPath('step.assignPolicy', 'ANY');
        $this->assertNotEmpty($res->json('starter.fullName'));
        $this->assertSame([self::MANAGER], array_column($res->json('users'), 'userId'));
    }

    public function test_context_blocks_when_no_recipient_and_gives_persian_message(): void
    {
        $defId = $this->publish([['assigneeType' => 'DIRECT_MANAGER']]);
        DB::update('UPDATE UserPositions SET IsActive = 0 WHERE UserID = ?', [self::STARTER]); // فقط داخلِ Transaction تست

        $res = $this->as(self::STARTER)->getJson("/workflow/definitions/{$defId}/preview-assignees");

        $res->assertOk()->assertJsonPath('startable', false)->assertJsonPath('reason', 'NO_ASSIGNEE_FOUND');
        $this->assertStringContainsString('گیرنده', $res->json('message'));
    }

    /**
     * از این پس CONDITION مانعِ Startِ نامهٔ فرایندی نیست (مسیرِ Deferred) — Context فقط
     * علامتِ «بعداً مشخص می‌شود» می‌گذارد؛ startable=true و Submit را Block نمی‌کند.
     */
    public function test_context_reports_deferred_not_blocked_for_condition_first_definition(): void
    {
        $defId = $this->publish([['assigneeType' => 'DIRECT_MANAGER']], conditionFirst: true);

        $res = $this->as(self::STARTER)->getJson("/workflow/definitions/{$defId}/preview-assignees");
        $res->assertOk()
            ->assertJsonPath('startable', true)
            ->assertJsonPath('reason', 'CONDITION')
            ->assertJsonPath('deferred', true)
            ->assertJsonPath('users', []);
    }

    /* ============================ resolve-preview ============================ */

    public function test_resolve_preview_resolves_user_and_system_tokens_without_form_values(): void
    {
        $defId = $this->publish([['assigneeType' => 'DIRECT_MANAGER']]);
        $tplId = $this->template($defId);

        $res = $this->as(self::STARTER)->postJson("/workflow/templates/{$tplId}/resolve-preview");

        $res->assertOk()->assertJsonPath('success', true);
        $this->assertArrayHasKey('USER_FULL_NAME', $res->json('resolved'));
        $this->assertNotSame('', $res->json('resolved.USER_FULL_NAME'));
        $this->assertArrayHasKey('TODAY', $res->json('resolved'));
        $this->assertEqualsCanonicalizing(['FROM_DATE', 'TO_DATE'], $res->json('pending'));
        $this->assertSame([], $res->json('unresolved'));
    }

    public function test_legacy_render_remains_all_or_nothing(): void
    {
        $defId = $this->publish([['assigneeType' => 'DIRECT_MANAGER']]);
        $tplId = $this->template($defId);

        // رفتارِ قدیمیِ render() بدونِ تغییر: بدونِ مقدارِ FORM، خطا می‌دهد
        $this->as(self::STARTER)->postJson("/workflow/templates/{$tplId}/render", ['formValues' => []])
            ->assertStatus(422);
    }

    /* ============================ POST /workflow/letters ============================ */

    public function test_letter_creates_single_task_message_for_real_recipient_and_starts_workflow(): void
    {
        $defId = $this->publish([['assigneeType' => 'DIRECT_MANAGER']]);
        $tplId = $this->template($defId);
        $before = $this->counts();

        $res = $this->as(self::STARTER)->postJson('/workflow/letters', $this->payload($defId, $tplId));

        $res->assertOk()->assertJsonPath('success', true)->assertJsonPath('instanceStatus', 'RUNNING');
        $messageId = (int) $res->json('messageId');

        $after = $this->counts();
        $this->assertSame(1, $after['messages'] - $before['messages'], 'دقیقاً یک Message.');
        $this->assertSame(1, $after['instances'] - $before['instances']);

        $msg = DB::selectOne('SELECT m.SenderUserID, m.Subject, m.MessageText, mt.MessageTypeName
            FROM Messages m JOIN MessageTypes mt ON mt.MessageTypeID = m.MessageTypeID WHERE m.MessageID = ?', [$messageId]);
        $this->assertSame('وظیفه', $msg->MessageTypeName);
        $this->assertSame(self::STARTER, (int) $msg->SenderUserID);
        $this->assertStringNotContainsString('{{', $msg->Subject . $msg->MessageText);
        $this->assertMatchesRegularExpression('#\d{4}/\d{2}/\d{2}#', $msg->MessageText, 'تاریخ‌ها به شمسی رندر شوند.');

        // تحویل: فقط به گیرندهٔ واقعی؛ فرستنده گیرنده نیست
        $to = array_map(fn ($r) => (int) $r->ToUserID, DB::select('SELECT ToUserID FROM MessageDetails WHERE MessageID = ?', [$messageId]));
        $this->assertSame([self::MANAGER], $to);

        // Task واقعیِ Step اول به همین Message وصل است
        $si = DB::selectOne('SELECT StepInstanceID, StepCode FROM WorkflowStepInstances WHERE MessageID = ?', [$messageId]);
        $this->assertNotNull($si);
        $this->assertSame('REVIEW', $si->StepCode);
        $assignees = array_map(fn ($r) => (int) $r->UserID, DB::select('SELECT UserID FROM WorkflowTaskAssignees WHERE StepInstanceID = ?', [$si->StepInstanceID]));
        $this->assertSame([self::MANAGER], $assignees);
    }

    public function test_letter_ignores_recipient_and_starter_supplied_by_client(): void
    {
        $defId = $this->publish([['assigneeType' => 'DIRECT_MANAGER']]);
        $tplId = $this->template($defId);

        $res = $this->as(self::STARTER)->postJson('/workflow/letters', $this->payload($defId, $tplId, [
            'RecipientUserIDs' => [self::OTHER],
            'RecipientType' => 2,
            'SenderUserID' => self::OTHER,
            'starter' => ['userId' => self::OTHER],
            'recipients' => [['userId' => self::OTHER]],
        ]));

        $res->assertOk();
        $messageId = (int) $res->json('messageId');
        $to = array_map(fn ($r) => (int) $r->ToUserID, DB::select('SELECT ToUserID FROM MessageDetails WHERE MessageID = ?', [$messageId]));
        $this->assertSame([self::MANAGER], $to);
        $this->assertSame(self::STARTER, (int) DB::selectOne('SELECT SenderUserID s FROM Messages WHERE MessageID = ?', [$messageId])->s);
    }

    public function test_no_recipient_returns_422_and_writes_nothing(): void
    {
        $defId = $this->publish([['assigneeType' => 'DIRECT_MANAGER']]);
        $tplId = $this->template($defId);
        DB::update('UPDATE UserPositions SET IsActive = 0 WHERE UserID = ?', [self::STARTER]);
        $before = $this->counts();

        $res = $this->as(self::STARTER)->postJson('/workflow/letters', $this->payload($defId, $tplId));

        $res->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('گیرنده', $res->json('message'));
        $this->assertNothingWritten($before);
    }

    /**
     * قبلاً (پیش از پشتیبانیِ مسیرِ Deferred) CONDITION قبلِ اولین Task با 422 رد می‌شد.
     * از این پس Engine خودش CONDITION را با Contextِ واقعی طی می‌کند و همان‌طور که برایِ
     * Definitionِ بدونِ CONDITION رفتار می‌کند، دقیقاً یک Message می‌سازد.
     */
    public function test_condition_first_definition_now_succeeds_via_deferred_path(): void
    {
        $defId = $this->publish([['assigneeType' => 'DIRECT_MANAGER']], conditionFirst: true);
        $tplId = $this->template($defId);
        $before = $this->counts();

        $res = $this->as(self::STARTER)->postJson('/workflow/letters', $this->payload($defId, $tplId));
        $res->assertOk()->assertJsonPath('success', true)->assertJsonPath('instanceStatus', 'RUNNING');
        $this->assertSame(1, $this->counts()['messages'] - $before['messages']);

        $messageId = (int) $res->json('messageId');
        $to = array_map(fn ($r) => (int) $r->ToUserID, DB::select('SELECT ToUserID FROM MessageDetails WHERE MessageID = ?', [$messageId]));
        $this->assertSame([self::MANAGER], $to, 'گیرندهٔ واقعیِ بعدِ CONDITION باید مدیرِ مستقیم باشد.');

        // رفتارِ عمومیِ Engine (مسیرِ عادی /workflow/instances) هم همچنان دست‌نخورده است
        $msg = DB::selectOne(
            "EXEC sp_InsertMessage @MessageTypeID = 1, @msgPriorityID = 1, @Subject = N'نامهٔ عادی', @MessageText = NULL,
                @RecipientType = 1, @RecipientUserIDs = ?, @CopyUserIDs = NULL, @CopyDescription = NULL,
                @SenderUserID = ?, @Year = 1405, @CreateUser = ?, @DueDate = NULL",
            [(string) self::MANAGER, self::STARTER, self::STARTER]
        );
        $this->as(self::STARTER)->postJson('/workflow/instances', [
            'definitionId' => $defId, 'entityType' => 'MESSAGE', 'entityId' => (int) $msg->NewMessageID, 'context' => [],
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_n_of_m_requires_enough_assignees_only_in_letter_path(): void
    {
        $tooFew = $this->publish([['assigneeType' => 'DIRECT_MANAGER']], policy: 'N_OF_M', required: 2);
        $tplFew = $this->template($tooFew);
        $before = $this->counts();

        $res = $this->as(self::STARTER)->postJson('/workflow/letters', $this->payload($tooFew, $tplFew));
        $res->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('تأیید', $res->json('message'));
        $this->assertNothingWritten($before);

        $enough = $this->publish(
            [['assigneeType' => 'DIRECT_MANAGER'], ['assigneeType' => 'USER', 'refId' => self::OTHER]],
            policy: 'N_OF_M',
            required: 2
        );
        $tplEnough = $this->template($enough);
        $this->as(self::STARTER)->postJson('/workflow/letters', $this->payload($enough, $tplEnough))->assertOk();
    }

    public function test_multiple_assignments_deliver_one_message_to_all_unique_recipients(): void
    {
        $defId = $this->publish(
            [['assigneeType' => 'DIRECT_MANAGER'], ['assigneeType' => 'USER', 'refId' => self::OTHER], ['assigneeType' => 'USER', 'refId' => self::MANAGER]],
            policy: 'ALL'
        );
        $tplId = $this->template($defId);
        $before = $this->counts();

        $res = $this->as(self::STARTER)->postJson('/workflow/letters', $this->payload($defId, $tplId));

        $res->assertOk();
        $this->assertSame(1, $this->counts()['messages'] - $before['messages']);
        $messageId = (int) $res->json('messageId');
        $to = array_map(fn ($r) => (int) $r->ToUserID, DB::select('SELECT ToUserID FROM MessageDetails WHERE MessageID = ? ORDER BY ToUserID', [$messageId]));
        $this->assertSame([self::MANAGER, self::OTHER], $to);
        $si = DB::selectOne('SELECT AssignPolicy FROM WorkflowStepInstances WHERE MessageID = ?', [$messageId]);
        $this->assertSame('ALL', $si->AssignPolicy);
    }

    public function test_template_of_another_definition_is_rejected(): void
    {
        $defA = $this->publish([['assigneeType' => 'DIRECT_MANAGER']]);
        $defB = $this->publish([['assigneeType' => 'DIRECT_MANAGER']]);
        $tplOfB = $this->template($defB);
        $before = $this->counts();

        $this->as(self::STARTER)->postJson('/workflow/letters', $this->payload($defA, $tplOfB))->assertStatus(422);
        $this->assertNothingWritten($before);
    }

    public function test_missing_form_value_returns_422_and_writes_nothing(): void
    {
        $defId = $this->publish([['assigneeType' => 'DIRECT_MANAGER']]);
        $tplId = $this->template($defId);
        $before = $this->counts();

        $this->as(self::STARTER)->postJson('/workflow/letters', $this->payload($defId, $tplId, ['formValues' => ['FROM_DATE' => '2026-10-01']]))
            ->assertStatus(422);
        $this->assertNothingWritten($before);
    }

    public function test_non_message_entity_definition_is_not_startable_as_letter(): void
    {
        $defId = $this->publish([['assigneeType' => 'DIRECT_MANAGER']], entityType: 'PROJECT');

        $this->as(self::STARTER)->getJson("/workflow/definitions/{$defId}/preview-assignees")
            ->assertOk()->assertJsonPath('startable', false)->assertJsonPath('reason', 'ENTITY_TYPE_MISMATCH');
    }

    public function test_failure_after_message_creation_rolls_everything_back(): void
    {
        $defId = $this->publish([['assigneeType' => 'DIRECT_MANAGER']]);
        $tplId = $this->template($defId);
        $before = $this->counts();

        // Adopt را عمداً شکست می‌دهیم (Messageِ «وظیفه» قبلاً ساخته شده و Instance ایجاد شده است)
        $this->app->instance(TaskService::class, new class($this->app->make(WorkflowStore::class), $this->app->make(WorkflowHistoryRecorder::class)) extends TaskService {
            public function adoptStepTask(object $ctx, object $step, int $stepInstanceId, array $assignees, ?int $actorUserId, int $messageId): int
            {
                throw new WorkflowStateException('شکستِ عمدیِ Adopt');
            }
        });

        $this->as(self::STARTER)->postJson('/workflow/letters', $this->payload($defId, $tplId))->assertStatus(409);
        $this->assertNothingWritten($before);
    }

    public function test_letter_requires_workflow_start_permission(): void
    {
        $defId = $this->publish([['assigneeType' => 'DIRECT_MANAGER']]);
        $tplId = $this->template($defId);

        $candidate = null;
        foreach (DB::select('SELECT UserID FROM Users WHERE IsActive = 1 ORDER BY UserID') as $u) {
            $codes = collect(DB::select('EXEC sp_GetUserPermissions @UserID = ?', [$u->UserID]))->pluck('PermissionCode')->all();
            if (! in_array('WORKFLOW_START', $codes, true)) {
                $candidate = (int) $u->UserID;
                break;
            }
        }
        if ($candidate === null) {
            $this->markTestSkipped('کاربری بدونِ WORKFLOW_START در دیتابیس نیست.');
        }
        $before = $this->counts();

        $this->as($candidate)->postJson('/workflow/letters', $this->payload($defId, $tplId))->assertStatus(403);
        $this->assertNothingWritten($before);
    }

    /* ============================ CC روی Message «وظیفه» ============================ */

    public function test_cc_is_stored_on_task_message_and_follows_existing_access_rules(): void
    {
        if (! $this->spSupportsCopy()) {
            $this->markTestSkipped('sp_Wf_CreateTaskMessage هنوز پارامترهایِ @CopyUserIDs/@CopyDescription را ندارد (تغییرِ SP اعمال نشده).');
        }

        $defId = $this->publish([['assigneeType' => 'DIRECT_MANAGER']]);
        $tplId = $this->template($defId);

        $res = $this->as(self::STARTER)->postJson('/workflow/letters', $this->payload($defId, $tplId, [
            'CopyUserIDs' => [self::OTHER, self::STARTER, self::OTHER, 999999],
            'CopyDescription' => 'جهتِ اطلاع',
        ]));

        $res->assertOk();
        $messageId = (int) $res->json('messageId');

        // همان قواعدِ sp_InsertMessage: یکتا، فقط کاربرِ فعال، بدونِ فرستنده
        $copies = DB::select('SELECT UserID, Description FROM MessageCopies WHERE MessageID = ?', [$messageId]);
        $this->assertCount(1, $copies);
        $this->assertSame(self::OTHER, (int) $copies[0]->UserID);
        $this->assertSame('جهتِ اطلاع', $copies[0]->Description);

        // اعلان: یک بار برایِ گیرنده و یک بار برایِ رونوشت
        $notified = array_map(fn ($r) => (int) $r->UserID, DB::select('SELECT UserID FROM UserNotifications WHERE MessageID = ? ORDER BY UserID', [$messageId]));
        $this->assertSame([self::MANAGER, self::OTHER], $notified);

        // دسترسی: رونوشت‌گیرنده مشارکت‌کننده است (مثلِ سایرِ Messageها) و غیرمرتبط نه
        $checker = $this->app->make(\App\Services\Message\MessageAccessChecker::class);
        $this->assertTrue($checker->isParticipant($messageId, self::OTHER));
        $this->assertTrue($checker->isParticipant($messageId, self::MANAGER));

        // رونوشت‌گیرنده در لیستِ پیام‌ها (Mode=1) همان Message را می‌بیند
        $list = DB::select('EXEC sp_GetMessages @UserID = ?, @Mode = 1', [self::OTHER]);
        $this->assertNotNull(collect($list)->firstWhere('MessageID', $messageId), 'رونوشت باید در لیستِ پیام‌هایِ گیرندهٔ رونوشت دیده شود.');
    }

    public function test_task_message_without_cc_calls_sp_exactly_as_before(): void
    {
        // Backward-compat: بدونِ CC، هیچ پارامترِ اضافه‌ای به SP ارسال نمی‌شود (SP قدیمی هم کار می‌کند).
        $store = $this->app->make(WorkflowStore::class);
        $msg = $store->createTaskMessage([
            'subject' => 'بدونِ رونوشت', 'senderUserId' => self::STARTER, 'assigneeUserIds' => [self::MANAGER], 'createUser' => self::STARTER,
        ]);
        $this->assertSame([], DB::select('SELECT 1 x FROM MessageCopies WHERE MessageID = ?', [(int) $msg->MessageID]));
    }
}
