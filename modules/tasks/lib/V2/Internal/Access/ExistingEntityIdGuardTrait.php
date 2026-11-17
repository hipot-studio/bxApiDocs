<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access;

use Bitrix\Tasks\V2\Internal\Entity\EntityCollectionInterface;
use Bitrix\Tasks\V2\Internal\Entity\EntityInterface;

trait ExistingEntityIdGuardTrait
{
	use AccessUserErrorTrait;

	protected function guardExistingEntityId(EntityInterface|EntityCollectionInterface $entity): bool
	{
		if ($entity instanceof EntityCollectionInterface)
		{
			foreach ($entity as $item)
			{
				if (!$item instanceof EntityInterface || !ExistingEntityId::isPositive($item->getId()))
				{
					$this->setUserError(ExistingEntityId::errorFor($entity));

					return false;
				}
			}

			return true;
		}

		if (!ExistingEntityId::isPositive($entity->getId()))
		{
			$this->setUserError(ExistingEntityId::errorFor($entity));

			return false;
		}

		return true;
	}
}
