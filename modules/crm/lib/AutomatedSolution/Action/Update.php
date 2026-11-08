<?php

namespace Bitrix\Crm\AutomatedSolution\Action;

use Bitrix\Crm\AutomatedSolution\Entity\AutomatedSolutionTable;
use Bitrix\Crm\Comparer\ComparerBase;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\AutomatedSolutionVibe;
use Bitrix\Crm\Integration\IntranetManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Intranet\CustomSection\Entity\CustomSectionTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

final class Update implements Action
{
	public function __construct(
		private readonly int $id,
		private readonly array $previousFields,
		private readonly array $currentFields,
	)
	{
	}

	public function execute(): Result
	{
		$diff = ComparerBase::compareEntityFields($this->previousFields, $this->currentFields);

		$hasTitleChange = $diff->isChanged('TITLE');
		$isVibeFeatureEnabled = Feature::enabled(AutomatedSolutionVibe::class);
		$hasVibeToggled = $isVibeFeatureEnabled && $diff->isChanged('IS_VIBE_ENABLED');
		$hasDescriptionChange = $this->hasDescriptionChange();

		if (!$hasTitleChange && !$hasVibeToggled && !$hasDescriptionChange)
		{
			// nothing to change
			return new Result();
		}

		if (!IntranetManager::isCustomSectionsAvailable())
		{
			return new Result();
		}

		$overallResult = new Result();

		$automatedSolution = AutomatedSolutionTable::getById($this->id)->fetchObject();
		if (!$automatedSolution)
		{
			return $overallResult->addError(
				new Error('Automated solution is not found', 0, ['automatedSolutionId' => $this->id]),
			);
		}

		$intranetSection = CustomSectionTable::getById($automatedSolution->getIntranetCustomSectionId())->fetchObject();
		if (!$intranetSection)
		{
			return $overallResult->addError(
				new Error(
					'Intranet custom section not found. Possible DB data inconsistency',
					0,
					[
						'automatedSolutionId' => $this->id,
						'customSectionId' => $automatedSolution->getIntranetCustomSectionId(),
					],
				),
			);
		}

		if ($hasTitleChange) // Update intranet section title and code only if AS title has changed
		{
			$intranetSection
				->setTitle($diff->getCurrentValue('TITLE'))
				->setCode('') // to provoke CODE regeneration
			;

			$intranetResult = $intranetSection->save();
			if (!$intranetResult->isSuccess())
			{
				return $overallResult->addErrors($intranetResult->getErrors());
			}
		}

		$automatedSolution
			->setTitle($intranetSection->getTitle())
			->setCode($intranetSection->getCode())
			->setIntranetCustomSectionId($intranetSection->getId())
			->setUpdatedTime(new DateTime())
			->setUpdatedBy(Container::getInstance()->getContext()->getUserId())
		;

		if ($hasDescriptionChange)
		{
			$automatedSolution->setDescription($this->currentFields['DESCRIPTION']);
		}

		$crmResult = $automatedSolution->save();
		if (!$crmResult->isSuccess())
		{
			return $overallResult->addErrors($crmResult->getErrors());
		}

		if ($hasVibeToggled)
		{
			$lifecycle = Container::getInstance()->getAutomatedSolutionVibeLifecycleService();
			$lifecycleResult = AutomatedSolutionVibe::normalizeValue($diff->getCurrentValue('IS_VIBE_ENABLED'))
				? $lifecycle->enable($this->id)
				: $lifecycle->disable($this->id);
			if (!$lifecycleResult->isSuccess())
			{
				$overallResult->addErrors($lifecycleResult->getErrors());
			}

			$automatedSolution = AutomatedSolutionTable::getById($this->id)->fetchObject();
		}

		$fields = $automatedSolution->collectValues();
		$fields['IS_VIBE_ENABLED'] = AutomatedSolutionVibe::normalizeValue($fields['IS_VIBE_ENABLED'] ?? false);
		$fields['TYPE_IDS'] = $this->previousFields['TYPE_IDS'] ?? [];

		return $overallResult->setData(['fields' => $fields]);
	}

	// Difference::isChanged() treats all empty values as equal and so would miss a description "0"
	private function hasDescriptionChange(): bool
	{
		return (
			array_key_exists('DESCRIPTION', $this->currentFields)
			&& $this->currentFields['DESCRIPTION'] !== ($this->previousFields['DESCRIPTION'] ?? null)
		);
	}
}
