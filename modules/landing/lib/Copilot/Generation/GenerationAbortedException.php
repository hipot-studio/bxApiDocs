<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation;

use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;

/**
 * The run has found the abort of another process - Generation::cancel() - on a step it was executing:
 * the Error status is already in the database and adopted by the scenarist, so the run ends here
 * without a status write of its own and the generation handles the end as a terminal failure.
 *
 * Thrown only after Scenarist::isAbortedOutside() has adopted the mark: the scenarist does not mark
 * the executed step again, the mark it carries is the abort's own.
 */
class GenerationAbortedException extends GenerationException
{
	public function __construct(string $message = 'The generation was cancelled')
	{
		parent::__construct(GenerationErrors::default, $message);
	}
}
