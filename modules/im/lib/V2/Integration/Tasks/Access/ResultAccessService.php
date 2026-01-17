<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Access;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Result;
use Bitrix\Tasks\V2\Public;

class ResultAccessService
{
	private ?Public\Service\Access\ResultAccessService $accessService = null;

	public function __construct()
	{
		if (Loader::includeModule('tasks'))
		{
			$this->accessService = Container::getInstance()->get(Public\Service\Access\ResultAccessService::class);
		}
	}

	public function canSave(int $userId, Result $result): bool
	{
		return (bool)$this->accessService?->canSave($userId, $result);
	}
}
