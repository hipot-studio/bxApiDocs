<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation;

/**
 * Marker exception for a cooperative step exit by the execution time budget.
 * It is not a failure: the step stays executable and is continued by the repeating agent,
 * so the scenarist must not mark such a step as errored.
 */
class GenerationTimeSliceException extends \RuntimeException
{
}
