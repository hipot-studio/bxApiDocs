<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Context;

class Context
{
	public function __construct(
		private int $userId,
	)
	{
	}

	public function setUserId(int $userId): self
	{
		$this->userId = $userId;

		return $this;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}
}
