<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Access\Result\Permission;

use Attribute;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorInterface;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorTrait;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;
use Bitrix\Tasks\V2\Internal\Access\Factory\AccessControllerTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Entity\Message;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Add implements AttributeAccessInterface, AccessUserErrorInterface
{
	use AccessControllerTrait;
	use AccessUserErrorTrait;

	public function check(Entity\EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		if (!$entity instanceof Message)
		{
			return false;
		}

		$result = new Entity\Result(messageId: $entity->getId());

		$accessController = $this->getAccessController(Type::Result, $context);
		$adapter = $this->getAdapter($result);

		$model = $adapter->transform();

		$accessResult = $accessController->check(ActionDictionary::ACTION_RESULT_CREATE_FROM_MESSAGE, $model);
		if (!$accessResult)
		{
			$this->resolveUserError($accessController);
		}

		return $accessResult;
	}
}
