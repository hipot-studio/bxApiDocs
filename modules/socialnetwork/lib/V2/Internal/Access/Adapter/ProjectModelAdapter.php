<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Adapter;

use Bitrix\SocialNetwork\Collab\Access\Model\CollabModel;
use Bitrix\Socialnetwork\V2\Internal\Entity;

class ProjectModelAdapter implements EntityModelAdapterInterface
{
	public function __construct(
		private readonly Entity\EntityInterface $entity
	)
	{
	}

	public function transform(): ?CollabModel
	{
		if (!$this->entity instanceof Entity\Project\Project)
		{
			return null;
		}

		$id = (int)$this->entity->getId();

		if ($id > 0)
		{
			return CollabModel::createFromId($id);
		}

		return new CollabModel();
	}

	public function create(): ?CollabModel
	{
		$id = (int)$this->entity->getId();

		if ($id <= 0)
		{
			return null;
		}

		return CollabModel::createFromId($id);
	}
}
