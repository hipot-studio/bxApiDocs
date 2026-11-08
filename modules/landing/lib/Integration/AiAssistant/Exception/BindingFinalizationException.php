<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Exception;

/**
 * Internal failure after the site has already been created: the chat binding could not be
 * finalized, so the completed status must not be returned.
 */
final class BindingFinalizationException extends \RuntimeException
{
	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct('Failed to finalize AI site binding before returning completed status.', 0, $previous);
	}
}
