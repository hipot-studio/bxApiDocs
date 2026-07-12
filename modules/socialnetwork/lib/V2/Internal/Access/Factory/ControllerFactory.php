<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Factory;

use Bitrix\Main\Access\AccessibleController;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\BaseAccessController;
use Bitrix\SocialNetwork\Collab\Access\CollabAccessController;
use Bitrix\SocialNetwork\Collab\Access\Model\CollabModel;
use Bitrix\Socialnetwork\Helper\SingletonTrait;
use Bitrix\Socialnetwork\V2\Internal\Access\Adapter\EntityModelAdapterInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\Adapter\ProjectModelAdapter;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;

class ControllerFactory implements ControllerFactoryInterface
{
	use SingletonTrait;

	public function create(Type $type, int $userId): ?AccessibleController
	{
		$class = $this->getClass($type);

		return $this->createByClass($class, $userId);
	}

	public function createByClass(string $class, int $userId): ?AccessibleController
	{
		if (is_subclass_of($class, BaseAccessController::class))
		{
			return $class::getInstance($userId);
		}

		if (is_subclass_of($class, AccessibleController::class))
		{
			return new $class($userId);
		}

		return null;
	}

	public function createAdapter(EntityInterface $entity): ?EntityModelAdapterInterface
	{
		return match ($entity::class) {
			Project::class => new ProjectModelAdapter($entity),
			default => null,
		};
	}

	public function createModel(Type $type): AccessibleItem
	{
		return match ($type) {
			Type::Project => CollabModel::createFromId(0),
		};
	}

	private function getClass(Type $type): string
	{
		return match ($type) {
			Type::Project => CollabAccessController::class,
		};
	}
}
