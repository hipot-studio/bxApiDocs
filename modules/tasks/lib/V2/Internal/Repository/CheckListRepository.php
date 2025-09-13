<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Tasks\CheckList\CheckListFacade;
use Bitrix\Tasks\CheckList\Task\TaskCheckListFacade;
use Bitrix\Tasks\CheckList\Template\TemplateCheckListFacade;
use Bitrix\Tasks\V2\Internal\Entity;

class CheckListRepository implements CheckListRepositoryInterface
{
	public function getIdsByEntity(int $entityId, Entity\CheckList\Type $type): array
	{
		/** @var CheckListFacade $dataClass */
		$dataClass = match ($type)
		{
			Entity\CheckList\Type::Task => TaskCheckListFacade::class,
			Entity\CheckList\Type::Template => TemplateCheckListFacade::class,
		};

		/** @var DataManager $dataTable */
		$dataTable = $dataClass::getCheckListDataController();

		$checkLists = $dataTable::query()
			->setSelect(['ID'])
			->where($dataClass::$entityIdName, $entityId)
			->fetchAll();

		$ids = [];
		foreach ($checkLists as $checkList)
		{
			$ids[] = (int)$checkList['ID'];
		}

		return $ids;
	}
}