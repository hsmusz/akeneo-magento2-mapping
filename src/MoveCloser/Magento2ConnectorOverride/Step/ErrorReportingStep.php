<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\Step;

use Akeneo\Tool\Component\Batch\Model\StepExecution;
use Webkul\Magento2Bundle\Step\x\BaseStep;

/**
 * Akeneo's step and job catch only \Exception, so a PHP \Error (TypeError, ValueError) kills the
 * process and the job ends as a bare FAILED with the reason only in the log. Rethrown as an
 * exception, it is stored as the step failure and shown in the job's UI.
 *
 * @author MoveCloser
 */
class ErrorReportingStep extends BaseStep
{
    public function doExecute(StepExecution $stepExecution)
    {
        try {
            return parent::doExecute($stepExecution);
        } catch (\Error $e) {
            throw new \RuntimeException(
                sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), basename($e->getFile()), $e->getLine()),
                0,
                $e
            );
        }
    }
}
