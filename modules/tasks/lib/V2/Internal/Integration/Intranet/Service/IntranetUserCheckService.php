<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Intranet\Service;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Factory\UserFactory;

class IntranetUserCheckService
{
	public function __construct(
		private readonly UserFactory $userFactory
	)
	{
	}

	public function isIntranet(int $userId): bool
	{
		if (!$this->isModuleInstalled())
		{
			return false;
		}

		return $this->userFactory
			->createEmptyFromId($userId)
			->isIntranet();
	}

	public function isExtranet(int $userId): bool
	{
		if (!$this->isModuleInstalled())
		{
			return false;
		}

		return $this->userFactory
			->createEmptyFromId($userId)
			->isExtranet();
	}

	private function isModuleInstalled(): bool
	{
		return Loader::includeModule('intranet');
	}
}
