<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Template\Permission;

use Attribute;
use Bitrix\Main\Access\AccessibleController;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TemplateAccessController;
use Bitrix\Tasks\V2\Internal\Access\Adapter\TemplateModelAdapter;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\AccessControllerTrait;
use Bitrix\Tasks\V2\Internal\Access\Registry\TemplateAccessCacheLoader;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Update implements AttributeAccessInterface
{
	use AccessControllerTrait;

	public function check(Entity\EntityInterface|Entity\EntityCollectionInterface $entity, Context $context, array $parameters = []): bool
	{
		$accessController = TemplateAccessController::getInstance($context->getUserId());

		if ($entity instanceof Entity\EntityInterface)
		{
			return $this->checkEntity($entity, $accessController);
		}

		Container::getInstance()->get(TemplateAccessCacheLoader::class)->preload($entity->getIds());

		foreach ($entity as $item)
		{
			if (!$this->checkEntity($item, $accessController))
			{
				return false;
			}
		}

		return true;
	}

	private function checkEntity(Entity\EntityInterface $entity, AccessibleController $accessController): bool
	{
		if (!$entity instanceof Entity\Template)
		{
			return false;
		}

		$adapter = $this->getAdapter($entity);

		$before = $adapter->create();
		$after = $adapter->transform();

		return $accessController->check(ActionDictionary::ACTION_TEMPLATE_SAVE, $before, $after);
	}
}
