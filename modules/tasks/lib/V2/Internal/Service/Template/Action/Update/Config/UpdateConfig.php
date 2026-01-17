<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config;

class UpdateConfig
{
	private int $userId;
	private bool $skipAgent;

	public function __construct(
		int $userId,
		bool $skipAgent = false,
	)
	{
		$this->userId = $userId;
		$this->skipAgent = $skipAgent;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function isSkipAgent(): bool
	{
		return $this->skipAgent;
	}
}
