<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Service\Access;

use Bitrix\Tasks\DI\Container;
use Bitrix\Tasks\V2\Internal\Access;
use Bitrix\Tasks\V2\Internal\Entity;

class ResultAccessService
{
	private readonly Access\Service\ResultAccessService $delegate;

	public function __construct()
	{
		$this->delegate = Container::getInstance()->get(Access\Service\ResultAccessService::class);
	}

	public function canSave(int $userId, Entity\Result $result): bool
	{
		return $this->delegate->canSave($userId, $result);
	}
}
