<?php

namespace Bitrix\Crm\AutomatedSolution\Action;

use Bitrix\Crm\AutomatedSolution\AutomatedSolutionManager;
use Bitrix\Crm\AutomatedSolution\Entity\AutomatedSolutionTable;
use Bitrix\Crm\AutomatedSolution\Entity\EO_AutomatedSolution;
use Bitrix\Crm\Integration\IntranetManager;
use Bitrix\Crm\Model\Dynamic\TypeTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Intranet\CustomSection\Entity\CustomSectionPageTable;
use Bitrix\Intranet\CustomSection\Entity\CustomSectionTable;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class Delete implements Action
{
	public function __construct(
		private readonly int $automatedSolutionId,
		private readonly bool $canDeleteIfTypesBound = false, // for backwards compatibility in LegacySet
	)
	{
	}

	public function execute(): Result
	{
		if (!IntranetManager::isCustomSectionsAvailable())
		{
			return new Result();
		}

		$automatedSolution = AutomatedSolutionTable::query()
			->setSelect(['*', 'TYPES'])
			->where('ID', $this->automatedSolutionId)
			->fetchObject()
		;
		if (!$automatedSolution)
		{
			// nothing to change
			return new Result();
		}

		$overallResult = new Result();

		if ($automatedSolution->getTypes()->count() > 0)
		{
			if (!$this->canDeleteIfTypesBound)
			{
				return $overallResult->addError(
					new Error(
						Loc::getMessage('CRM_AUTOMATED_SOLUTION_ACTION_DELETE_CANT_DELETE_IF_TYPES_BOUND'),
						'HAS_BOUND_TYPES',
					),
				);
			}

			$deletePagesResult = $this->deletePages($automatedSolution);
			if (!$deletePagesResult->isSuccess())
			{
				$overallResult->addErrors($deletePagesResult->getErrors());
			}
		}

		$customSectionDeleteResult = CustomSectionTable::delete($automatedSolution->getIntranetCustomSectionId());
		if (!$customSectionDeleteResult->isSuccess())
		{
			$overallResult->addErrors($customSectionDeleteResult->getErrors());
		}

		// remember ids before delete, we cant get them afterwards
		$boundTypeIds = $automatedSolution->getTypes()->getIdList();

		$automatedSolutionDeleteResult = $automatedSolution->delete();
		if (!$automatedSolutionDeleteResult->isSuccess())
		{
			$overallResult->addErrors($automatedSolutionDeleteResult->getErrors());
		}

		if (!empty($boundTypeIds))
		{
			$typesUpdateResult = TypeTable::updateMulti(
				$boundTypeIds,
				['CUSTOM_SECTION_ID' => null],
			);
			if (!$typesUpdateResult->isSuccess())
			{
				$overallResult->addErrors($typesUpdateResult->getErrors());
			}
		}

		Container::getInstance()->getDynamicTypesMap()->invalidateTypesCollectionCache();

		return $overallResult;
	}

	private function deletePages(EO_AutomatedSolution $automatedSolution): Result
	{
		$pagesDbResult = CustomSectionPageTable::query()
			->setSelect(['ID'])
			->where('CUSTOM_SECTION_ID', $automatedSolution->getIntranetCustomSectionId())
			->where('MODULE_ID', AutomatedSolutionManager::MODULE_ID)
			->whereIn(
				'SETTINGS',
				array_map(
					fn(int $entityTypeId) => IntranetManager::preparePageSettingsForItemsList($entityTypeId),
					$automatedSolution->getTypes()->getEntityTypeIdList(),
				),
			)
		;

		$result = new Result();

		while ($page = $pagesDbResult->fetchObject())
		{
			$pageDeleteResult = $page->delete();
			if (!$pageDeleteResult->isSuccess())
			{
				$result->addErrors($pageDeleteResult->getErrors());
			}
		}

		return $result;
	}
}
