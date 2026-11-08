<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Exception;

/**
 * The user has no permission to create landing sites.
 */
final class SiteCreateAccessDeniedException extends \RuntimeException
{
	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct('Access denied. You do not have permission to create landing sites.', 0, $previous);
	}
}
