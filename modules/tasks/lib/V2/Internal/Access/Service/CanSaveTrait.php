<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Service;

use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Entity\EntityInterface;

trait CanSaveTrait
{
	abstract private function getEntityById(int $id): ?EntityInterface;

	private function canSaveInternal(
		Type $type,
		ControllerFactoryInterface $controllerFactory,
		string $saveAction,
		int $userId,
		EntityInterface $entity,
	): bool
	{
		if ($entity->getId() <= 0)
		{
			return $this->canCreateInternal(
				type: $type,
				controllerFactory: $controllerFactory,
				saveAction: $saveAction,
				userId: $userId,
				entity: $entity,
			);
		}

		return $this->canUpdateInternal(
			type: $type,
			controllerFactory: $controllerFactory,
			saveAction: $saveAction,
			userId: $userId,
			entity: $entity,
		);
	}

	private function canCreateInternal(
		Type $type,
		ControllerFactoryInterface $controllerFactory,
		string $saveAction,
		int $userId,
		EntityInterface $entity,
	): bool
	{
		$adapter = $controllerFactory->createAdapter($entity);

		$entityItem = $adapter?->transform($entity);
		if ($entityItem === null)
		{
			return false;
		}

		$controller = $controllerFactory->create($type, $userId);
		if ($controller === null)
		{
			return false;
		}

		return $controller->check($saveAction, null, $entityItem);
	}

	private function canUpdateInternal(
		Type $type,
		ControllerFactoryInterface $controllerFactory,
		string $saveAction,
		int $userId,
		EntityInterface $entity,
	): bool
	{
		$adapter = $controllerFactory->createAdapter($entity);

		$before = $adapter?->create();
		if ($before === null)
		{
			return false;
		}

		$current = $this->getEntityById($entity->getId());
		if ($current === null)
		{
			return false;
		}

		$after = $adapter?->transform($current);
		if ($after === null)
		{
			return false;
		}

		$controller = $controllerFactory->create($type, $userId);
		if ($controller === null)
		{
			return false;
		}

		return $controller->check($saveAction, $before, $after);
	}
}
