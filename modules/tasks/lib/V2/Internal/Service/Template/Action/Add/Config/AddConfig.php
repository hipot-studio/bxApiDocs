<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Config;

class AddConfig
{
	private int $userId;

	public function __construct(
		int $userId,
	)
	{
		$this->userId = $userId;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}
}
