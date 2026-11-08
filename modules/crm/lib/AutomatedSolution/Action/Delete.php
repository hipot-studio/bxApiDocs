<?php

namespace Bitrix\Crm\AutomatedSolution\Action;

use Bitrix\Crm\AutomatedSolution\Entity\AutomatedSolutionTable;
use Bitrix\Crm\Integration\IntranetManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Intranet\CustomSection\Entity\CustomSectionTable;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class Delete implements Action
{
	public function __construct(
		private readonly int $automatedSolutionId,
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
			->setSelect(['*', 'TYPES.ID'])
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
			return $overallResult->addError(
				new Error(
					Loc::getMessage('CRM_AUTOMATED_SOLUTION_ACTION_DELETE_CANT_DELETE_IF_TYPES_BOUND'),
					'HAS_BOUND_TYPES',
				),
			);
		}

		$lifecycleResult = Container::getInstance()->getAutomatedSolutionVibeLifecycleService()->delete(
			$this->automatedSolutionId,
			static function (array $state): Result {
				$customSectionDeleteResult = CustomSectionTable::delete((int)$state['INTRANET_CUSTOM_SECTION_ID']);
				if (!$customSectionDeleteResult->isSuccess())
				{
					return (new Result())->addErrors($customSectionDeleteResult->getErrors());
				}

				return AutomatedSolutionTable::delete((int)$state['ID']);
			},
		);
		if (!$lifecycleResult->isSuccess())
		{
			return $overallResult->addErrors($lifecycleResult->getErrors());
		}

		Container::getInstance()->getDynamicTypesMap()->invalidateTypesCollectionCache();

		$event = new Event('crm', 'onAfterAutomatedSolutionDelete', [
			'automatedSolution' => [
				'ID' => $this->automatedSolutionId,
			]
		]);
		$event->send();

		return $overallResult;
	}
}
