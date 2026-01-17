<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Task\Tracking\Elapsed\Permission;

use Attribute;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;
use Bitrix\Tasks\V2\Internal\Access\Factory\AccessControllerTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Entity;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Delete implements AttributeAccessInterface
{
	use AccessControllerTrait;

	public function check(Entity\EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		if (!$entity instanceof Entity\Task\ElapsedTime)
		{
			return false;
		}

		$accessController = $this->getAccessController(Type::ElapsedTime, $context);
		$adapter = $this->getAdapter($entity);

		$model = $adapter->create();

		return $accessController->checkByItemId(ActionDictionary::ACTION_ELAPSED_TIME_DELETE, $model?->getId());
	}
}
