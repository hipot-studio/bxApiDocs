<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Exception;

/**
 * The user has issued too many links carrying a request within the last hour.
 */
final class HandoffLimitException extends HandoffLinkException
{
	public const ERROR_CODE = 'HANDOFF_LIMIT_REACHED';

	public function __construct(int $limit, ?\Throwable $previous = null)
	{
		parent::__construct(
			self::ERROR_CODE,
			"No more than {$limit} links carrying a request can be issued per hour. Try again later.",
			$previous,
		);
	}
}
