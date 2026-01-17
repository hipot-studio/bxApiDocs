<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Template\Permission\CheckList;

use Attribute;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TemplateAccessController;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Toggle implements AttributeAccessInterface
{
	public function check(Entity\EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		$checkListMapper = Container::getInstance()->getCheckListMapper();
		$templateRepository = Container::getInstance()->getTemplateRepository();

		if (!$entity->getId())
		{
			return false;
		}

		/** @var Entity\Template $entity */
		if ($entity->checklist === null)
		{
			return false;
		}

		$nodes = $checkListMapper->mapToNodes($entity->checklist);

		$canToggle = true;
		foreach ($nodes as $node)
		{
			if (
				!TemplateAccessController::can(
					userId: $context->getUserId(),
					action: ActionDictionary::ACTION_CHECKLIST_TOGGLE,
					itemId: $entity->getId(),
					params: $node->toArray(),
				)
			)
			{
				$canToggle = false;

				break;
			}
		}

		if (!$canToggle)
		{
			return false;
		}

		$template = $templateRepository->getById($entity->getId());

		if (!$template)
		{
			return false;
		}

		return true;
	}
}
