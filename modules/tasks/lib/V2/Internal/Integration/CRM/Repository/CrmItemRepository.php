<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Repository;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemCollection;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\Mapper\CrmIdMapper;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\Mapper\CrmItemMapper;
use CCrmOwnerType;

class CrmItemRepository implements CrmItemRepositoryInterface
{
	public function __construct(
		private readonly CrmItemMapper $crmItemMapper,
		private readonly CrmIdMapper $crmIdMapper,
	)
	{

	}

	public function getIdsByTaskId(int $taskId): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$row = TaskTable::query()
			->setSelect(['ID', UserField::TASK_CRM])
			->where('ID', $taskId)
			->fetch()
		;

		if (!is_array($row[UserField::TASK_CRM] ?? null))
		{
			return [];
		}

		return $row[UserField::TASK_CRM];
	}

	public function getByIds(array $ids): CrmItemCollection
	{
		if (!Loader::includeModule('crm'))
		{
			return new CrmItemCollection();
		}

		$items = [];
		foreach ($ids as $id)
		{
			[$typeId, $entityId] = $this->crmIdMapper->mapFromId($id);
			if ($entityId === null)
			{
				continue;
			}

			$typeName = $this->getTypeName($typeId);
			$title = $this->getItemTitle($entityId, $typeId);
			$url = $this->getItemUrl($entityId, $typeId);

			$items[] = [
				'id' => $id,
				'entityId' => $entityId,
				'typeId' => $typeId,
				'typeName' => $typeName,
				'title' => $title,
				'link' => $url,
			];
		}

		return $this->crmItemMapper->mapToCollection($items);
	}

	private function getTypeName(int $typeId): ?string
	{
		return Container::getInstance()->getFactory($typeId)?->getEntityDescription();
	}

	private function getItemTitle(int $entityId, int $typeId): string
	{
		$title = CCrmOwnerType::GetCaption($typeId, $entityId, false);

		return is_string($title) ? $title : '';
	}

	private function getItemUrl(int $entityId, int $typeId): string
	{
		return CCrmOwnerType::GetEntityShowPath($typeId, $entityId);
	}
}
