<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Bitrix24\Integrator;

use Bitrix\Bitrix24\Public\Command\Integrator\RemoveGroupCommand;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class IntegratorGroup
{
	private bool $isAvailable;

	public function __construct()
	{
		$this->isAvailable = Loader::includeModule('bitrix24');
	}

	public function removeByUserId(int $userId): Result
	{
		$result = new Result();

		if (!$this->isAvailable || $userId < 1)
		{
			return $result;
		}

		return (new RemoveGroupCommand($userId))->run();
	}
}
