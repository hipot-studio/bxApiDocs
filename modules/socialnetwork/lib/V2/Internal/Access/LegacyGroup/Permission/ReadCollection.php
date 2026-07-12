<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\LegacyGroup\Permission;

use Attribute;
use Bitrix\Socialnetwork\V2\Internal\Access\CollectionAttributeAccessInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\Context\Context;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\AbstractEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityCollectionInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
class ReadCollection implements CollectionAttributeAccessInterface
{
	public function checkCollection(EntityCollectionInterface $entity, Context $context, array $parameters = []): bool
	{
		if ($context->getUserId() <= 0 || !$entity instanceof AbstractEntityCollection)
		{
			return false;
		}

		$accessService = Container::getInstance()->getLegacyGroupAccessService();
		foreach ($entity->getIds() as $id)
		{
			if (!$accessService->canRead($context->getUserId(), $id))
			{
				$entity->remove($id);
			}
		}

		return true;
	}
}
