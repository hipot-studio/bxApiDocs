<?php

namespace Bitrix\Crm\AutomatedSolution\Action;

use Bitrix\Crm\AutomatedSolution\AutomatedSolutionManager;
use Bitrix\Crm\AutomatedSolution\Entity\AutomatedSolutionTable;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\AutomatedSolutionVibe;
use Bitrix\Crm\Integration\IntranetManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Intranet\CustomSection\Entity\CustomSectionTable;
use Bitrix\Main\Result;

final class Add implements Action
{
	public function __construct(
		private readonly array $fields
	)
	{
	}

	public function execute(): Result
	{
		if (!IntranetManager::isCustomSectionsAvailable())
		{
			return new Result();
		}

		$overallResult = new Result();
		$isVibeRequested = Feature::enabled(AutomatedSolutionVibe::class)
			&& AutomatedSolutionVibe::normalizeValue($this->fields['IS_VIBE_ENABLED'] ?? false);

		$intranetSection = CustomSectionTable::createObject([
			'TITLE' => $this->fields['TITLE'] ?? null,
			'MODULE_ID' => AutomatedSolutionManager::MODULE_ID,
		]);
		$intranetResult = $intranetSection->save();
		if (!$intranetResult->isSuccess())
		{
			return $overallResult->addErrors($intranetResult->getErrors());
		}

		$automatedSolution = AutomatedSolutionTable::createObject([
			'TITLE' => $intranetSection->getTitle(),
			'CODE' => $intranetSection->getCode(),
			'INTRANET_CUSTOM_SECTION_ID' => $intranetSection->getId(),
			'IS_VIBE_ENABLED' => false,
			'DESCRIPTION' => $this->fields['DESCRIPTION'] ?? null,
		]);
		$crmResult = $automatedSolution->save();
		if (!$crmResult->isSuccess())
		{
			return $overallResult->addErrors($crmResult->getErrors());
		}

		if ($isVibeRequested)
		{
			$lifecycleResult = Container::getInstance()
				->getAutomatedSolutionVibeLifecycleService()
				->enable($automatedSolution->getId())
			;
			if (!$lifecycleResult->isSuccess())
			{
				$overallResult->addErrors($lifecycleResult->getErrors());
			}

			$automatedSolution = AutomatedSolutionTable::getById($automatedSolution->getId())->fetchObject();
		}

		IntranetManager::clearLeftMenuCache();

		$fields = $automatedSolution->collectValues();
		$fields['IS_VIBE_ENABLED'] = AutomatedSolutionVibe::normalizeValue($fields['IS_VIBE_ENABLED'] ?? false);
		$fields['TYPE_IDS'] = [];

		return $overallResult->setData(['fields' => $fields]);
	}
}
