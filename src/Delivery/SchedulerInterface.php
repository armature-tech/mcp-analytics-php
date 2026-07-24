<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Delivery;

interface SchedulerInterface
{
    /**
     * The scheduler must retain and invoke the task before its runtime ends.
     *
     * @param \Closure(): void $task
     */
    public function schedule(\Closure $task): void;
}
