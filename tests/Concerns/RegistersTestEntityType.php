<?php

namespace Tests\Concerns;

use App\Services\Workflow\Entity\NullEntityResolver;

/**
 * ثبتِ یک EntityType فقط‌-محیطِ-تست (`TEST_ENTITY`) برایِ تست‌هایی که صرفاً
 * مکانیزمِ عمومیِ Engine (State Machine/Condition Gateway/Concurrency) را
 * می‌سنجند و به یک موجودیتِ کسب‌وکارِ واقعی (مثلِ Message) وابسته نیستند.
 *
 * این کار فقط با config() در حافظهٔ همین پردازشِ تستی انجام می‌شود؛ فایلِ
 * config/workflow.php واقعی هرگز تغییر نمی‌کند و TEST_ENTITY هیچ‌وقت در
 * Production ثبت نمی‌شود. باید **قبل از اولین Resolveِ EntityResolverRegistry**
 * (یعنی قبل از هر app()->make(WorkflowEngine::class) یا
 * WorkflowDefinitionService::class) صدا زده شود چون آن رجیستری Singleton است.
 */
trait RegistersTestEntityType
{
    protected function registerTestEntityType(): void
    {
        config(['workflow.entities.TEST_ENTITY' => [
            'label'    => 'موجودیتِ تستی (فقط محیطِ تست)',
            'resolver' => NullEntityResolver::class,
        ]]);
    }
}
