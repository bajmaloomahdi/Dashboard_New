<?php

namespace App\Services\Workflow\Entity;

use App\Services\Workflow\Exceptions\WorkflowValidationException;
use Illuminate\Contracts\Container\Container;

/**
 * رجیستریِ EntityResolverها بر پایهٔ config/workflow.php.
 *
 * معادلِ جدولِ WorkflowEntityProviders در فازهای بعد؛ کدِ مصرف‌کننده فقط
 * resolverFor($entityType) را صدا می‌زند و از منبعِ رجیستری بی‌خبر است.
 */
class EntityResolverRegistry
{
    /** @var array<string,EntityResolver> */
    private array $resolved = [];

    public function __construct(
        private Container $container,
        private array $config,
    ) {
    }

    public function isKnown(string $entityType): bool
    {
        return isset($this->config[$entityType]);
    }

    public function resolverFor(string $entityType): EntityResolver
    {
        if (isset($this->resolved[$entityType])) {
            return $this->resolved[$entityType];
        }

        if (! isset($this->config[$entityType])) {
            throw new WorkflowValidationException("نوعِ موجودیتِ «{$entityType}» در config/workflow.php ثبت نشده است.");
        }

        $class = $this->config[$entityType]['resolver'] ?? NullEntityResolver::class;

        return $this->resolved[$entityType] = $this->container->make($class);
    }

    public function label(string $entityType): string
    {
        return $this->config[$entityType]['label'] ?? $entityType;
    }
}
