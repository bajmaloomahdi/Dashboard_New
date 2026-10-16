<?php

namespace App\Services\Workflow\Dto;

/**
 * نتیجهٔ یک عملیاتِ موتور (شروع / اقدام روی تسک).
 */
final class EngineResult
{
    /**
     * @param  int          $instanceId
     * @param  string       $instanceStatus     RUNNING | COMPLETED | CANCELLED | FAILED | SUSPENDED
     * @param  int[]        $createdMessageIds  MessageIDِ آیتمِ کارتابلیِ درگیر در این عملیات — طبقِ
     *         قاعدهٔ «یک Instance = یک Message»، از اولین Taskِ کلِ Instance به بعد این همیشه همان
     *         Messageِ اصلی است (نه لزوماً تازه‌ساخته)، حتی وقتی advance() به Stepِ بعدی می‌رود.
     * @param  string|null  $enteredStepCode    کدِ مرحله‌ای که موتور در آن متوقف شده (اگر RUNNING)
     * @param  string|null  $message
     */
    public function __construct(
        public int $instanceId,
        public string $instanceStatus,
        public array $createdMessageIds = [],
        public ?string $enteredStepCode = null,
        public ?string $message = null,
    ) {
    }

    public function isCompleted(): bool
    {
        return $this->instanceStatus === 'COMPLETED';
    }
}
