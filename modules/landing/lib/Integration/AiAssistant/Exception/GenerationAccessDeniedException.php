<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Exception;

/**
 * The site generation job exists but belongs to another user.
 *
 * The class is separate because the callers type on it, the message is not: it repeats the wording
 * of GenerationNotFoundException so that a foreign job is indistinguishable from a missing one and
 * the autoincrement id space cannot be probed. Nothing outside may tell the two apart by the text.
 */
final class GenerationAccessDeniedException extends \RuntimeException
{
	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct(GenerationNotFoundException::MESSAGE, 0, $previous);
	}
}
