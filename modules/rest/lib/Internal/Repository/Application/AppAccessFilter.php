<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Application;

final class AppAccessFilter
{
	public function __construct(
		private readonly int $userId,
		private readonly array $accessCodes,
	)
	{
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getAccessCodes(): array
	{
		return $this->accessCodes;
	}
}
