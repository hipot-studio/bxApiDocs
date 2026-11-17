<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Intranet\Exception;

use RuntimeException;
use Throwable;

class UserAnnualSummaryException extends RuntimeException
{
	public function __construct(private readonly int $userId, Throwable $previous)
	{
		parent::__construct('Failed to create annual summary values for user.', 0, $previous);
	}

	public function getUserId(): int
	{
		return $this->userId;
	}
}
