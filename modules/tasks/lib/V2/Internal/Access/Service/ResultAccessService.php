<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Service;

use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Entity;

class ResultAccessService
{
	public function __construct(
		private readonly ControllerFactoryInterface $controllerFactory,
	)
	{
	}

	public function canSave(int $userId, Entity\Result $result): bool
	{
		$controller = $this->controllerFactory->create(Type::Result, $userId);
		if ($controller === null)
		{
			return false;
		}

		if ($result->getId() > 0)
		{
			return $controller->checkByItemId(ActionDictionary::ACTION_RESULT_EDIT, $result->getId());
		}

		$adapter = $this->controllerFactory->createAdapter($result);

		$model = $adapter?->transform($result);
		if ($model === null)
		{
			return false;
		}

		return $controller->check(ActionDictionary::ACTION_RESULT_READ, $model);
	}
}
