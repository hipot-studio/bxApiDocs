<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\CheckListMapper;
use Bitrix\Tasks\V2\Internal\Service\CheckList\CheckListFacadeResolver;
use Bitrix\Tasks\V2\Internal\Service\CheckList\CheckListTreeService;

class CheckListRepository implements CheckListRepositoryInterface
{
	public function __construct(
		private readonly CheckListMapper $checkListMapper,
		private readonly CheckListTreeService $treeService,
		private readonly CheckListFacadeResolver $facadeResolver,
	)
	{
	}

	/**
	 * @param int[] $entityIds
	 * @param Entity\CheckList\Type $type
	 *
	 * @return Entity\CheckList
	 */
	public function getByEntities(array $entityIds, Entity\CheckList\Type $type): Entity\CheckList
	{
		$facade = $this->facadeResolver->resolveByType($type);

		$items = $facade::getList(filter: [$facade::$entityIdName => $entityIds]);

		$items = $this->treeService->buildTree($items);

		return $this->checkListMapper->mapToEntity($items);
	}

	public function getByEntity(int $entityId, Entity\CheckList\Type $type): Entity\CheckList
	{
		$facade = $this->facadeResolver->resolveByType($type);

		$items = $facade::getByEntityId($entityId);

		$items = $this->treeService->buildTree($items);

		return $this->checkListMapper->mapToEntity($items);
	}

	public function getIdsByEntity(int $entityId, Entity\CheckList\Type $type): array
	{
		$facade = $this->facadeResolver->resolveByType($type);

		/** @var DataManager $dataTable */
		$dataTable = $facade::getCheckListDataController();

		$checkLists =
			$dataTable::query()
				->setSelect(['ID'])
				->where($facade::$entityIdName, $entityId)
				->fetchAll()
		;

		$ids = [];
		foreach ($checkLists as $checkList)
		{
			$ids[] = (int)$checkList['ID'];
		}

		return $ids;
	}

	public function getAttachmentIdsByEntity(int $entityId, Entity\CheckList\Type $type): array
	{
		$ids = $this->getIdsByEntity($entityId, $type);
		if (empty($ids))
		{
			return [];
		}

		$facade = $this->facadeResolver->resolveByType($type);

		/** @var DataManager $dataTable */
		$dataTable = $facade::getCheckListDataController();

		$checkLists =
			$dataTable::query()
				->setSelect(['ID', Entity\UF\UserField::CHECKLIST_ATTACHMENTS])
				->whereIn('ID', $ids)
				->fetchAll()
		;

		$attachments = array_column($checkLists, Entity\UF\UserField::CHECKLIST_ATTACHMENTS);
		$attachments = array_filter($attachments, 'is_array');
		$attachments = array_merge(...$attachments);

		Collection::normalizeArrayValuesByInt($attachments, false);

		return $attachments;
	}
}
