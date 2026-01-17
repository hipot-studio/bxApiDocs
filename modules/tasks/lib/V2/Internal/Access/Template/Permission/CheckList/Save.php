<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Template\Permission\CheckList;

use Attribute;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TemplateAccessController;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Save implements AttributeAccessInterface
{
	public function check(Entity\EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		if (!$entity instanceof Entity\Template)
		{
			return false;
		}

		return TemplateAccessController::can(
			$context->getUserId(),
			ActionDictionary::ACTION_TEMPLATE_EDIT,
			$entity->getId(),
		);
	}
}
