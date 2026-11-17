<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Template\Permission;

use Attribute;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TemplateAccessController;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorInterface;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Internal\Access\ExistingEntityIdGuardTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\AccessControllerTrait;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Delete implements AttributeAccessInterface, AccessUserErrorInterface
{
	use AccessControllerTrait;
	use ExistingEntityIdGuardTrait;

	public function check(Entity\EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		$accessController = TemplateAccessController::getInstance($context->getUserId());
		if (!$this->guardExistingEntityId($entity))
		{
			return false;
		}

		$adapter = $this->getAdapter($entity);
		$model = $adapter->create();

		return $accessController->check(ActionDictionary::ACTION_TEMPLATE_REMOVE, $model);
	}
}
