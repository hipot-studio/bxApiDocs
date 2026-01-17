<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Adapter;

use Bitrix\Tasks\Access\Model;
use Bitrix\Tasks\V2\Internal\Entity;

class ResultModelAdapter implements EntityModelAdapterInterface
{
	public function __construct(
		private readonly Entity\EntityInterface $entity
	)
	{

	}

	public function transform(?Entity\EntityInterface $current = null): ?Model\ResultModel
	{
		if (!$this->entity instanceof Entity\Result)
		{
			return null;
		}

		$data['id'] = (int)$this->entity->getId();
		$data['taskId'] = $this->entity->taskId;
		$data['createdBy'] = $this->entity->author?->getId();
		$data['messageId'] = $this->entity->messageId;

		return Model\ResultModel::createFromArray($data);
	}

	public function create(): ?Model\ResultModel
	{
		return Model\ResultModel::createFromId($this->entity->getId());
	}
}
