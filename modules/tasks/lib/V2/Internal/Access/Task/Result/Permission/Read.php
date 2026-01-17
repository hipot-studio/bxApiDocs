<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Task\Result\Permission;

use Attribute;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\AccessControllerTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Read implements AttributeAccessInterface
{
	use AccessControllerTrait;

	public function check(Entity\EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		if (!$entity instanceof Entity\Result)
		{
			return false;
		}

		return
			$this->getAccessController(Type::Result, $context)
				->checkByItemId(ActionDictionary::ACTION_RESULT_READ, $entity->getId());
	}
}
