<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Adapter;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Tasks\Access\Model;
use Bitrix\Tasks\V2\Internal\Entity;

class TemplateModelAdapter implements EntityModelAdapterInterface
{
	public function __construct(
		private readonly Entity\EntityInterface $entity
	)
	{

	}

	public function transform(): ?AccessibleItem
	{
		if (!$this->entity instanceof Entity\Template)
		{
			return null;
		}

		$data['ID'] = (int)$this->entity->getId();

		$data['GROUP_ID'] = (int)$this->entity->group?->getId();

		$hasMembers = false;
		if ($this->entity->creator !== null)
		{
			$data['CREATED_BY'] = $this->entity->creator->getId();
			$hasMembers = true;
		}

		if ($this->entity->responsibleCollection !== null)
		{
			$data['RESPONSIBLES'] = $this->entity->responsibleCollection->getIds();
			$hasMembers = true;
		}

		if ($this->entity->accomplices !== null)
		{
			$data['ACCOMPLICES'] = $this->entity->accomplices->getIds();
			$hasMembers = true;
		}

		if ($this->entity->auditors !== null)
		{
			$data['AUDITORS'] = $this->entity->auditors->getIds();
			$hasMembers = true;
		}

		if ($this->entity->base?->id !== null)
		{
			$data['BASE_TEMPLATE_ID'] = (int)$this->entity->base->id;
		}

		$model = Model\TemplateModel::createFromArray($data);

		if (!$hasMembers)
		{
			return $model->setMembers(null);
		}

		return $model;
	}

	public function create(): AccessibleItem
	{
		return Model\TemplateModel::createFromId($this->entity->getId());
	}
}
