<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Adapter;

use Bitrix\Main\Access;
use Bitrix\Tasks\V2\Internal\Access\Tracking\Elapsed\ElapsedTimeModel;
use Bitrix\Tasks\V2\Internal\Entity;

class ElapsedTimeModelAdapter implements EntityModelAdapterInterface
{
	public function __construct(
		private readonly Entity\EntityInterface $entity
	)
	{
	}

	public function transform(): ?Access\AccessibleItem
	{
		if (!$this->entity instanceof Entity\Task\ElapsedTime)
		{
			return null;
		}

		$data['id'] = (int)$this->entity->getId();
		$data['userId'] = (int)$this->entity->userId;

		return ElapsedTimeModel::createFromArray($data);
	}

	public function create(): ?Access\AccessibleItem
	{
		return ElapsedTimeModel::createFromId((int)$this->entity->getId());
	}
}
