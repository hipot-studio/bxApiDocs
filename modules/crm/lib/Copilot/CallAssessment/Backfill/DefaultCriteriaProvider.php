<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Backfill;

use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessment;
use Bitrix\Crm\Copilot\CallAssessment\FillPreliminaryCallAssessments;
use Bitrix\Crm\Copilot\CallAssessment\HintStateResolver;
use Bitrix\Main\Localization\Loc;

final class DefaultCriteriaProvider
{
	// Counts match the number of *_CRITERIA_{n} phrases defined in lang for each set.
	private const DEFAULT_CRITERIA_COUNT = 7;
	private const BASIC_CRITERIA_COUNT = 5;

	/**
	 * @return array<int, array{title: string, description: string}>
	 */
	public function getCriteriaForCallAssessment(CopilotCallAssessment $callAssessment): array
	{
		$code = (string)$callAssessment->getCode();
		if ($code === '' || !$this->isPromptUnchanged($callAssessment))
		{
			return [];
		}

		return $this->loadCriteriaByPrefix(
			"CRM_BACKFILL_DEFAULT_CRITERIA_" . strtoupper($code),
			self::DEFAULT_CRITERIA_COUNT,
		);
	}

	public function getDescriptionForCallAssessment(CopilotCallAssessment $callAssessment): string
	{
		$code = (string)$callAssessment->getCode();
		if ($code === '' || !$this->isPromptUnchanged($callAssessment))
		{
			return '';
		}

		return (string)FillPreliminaryCallAssessments::getReferenceDescriptionByCode($code);
	}

	/**
	 * @return array<int, array{title: string, description: string}>
	 */
	public function getBasicCriteria(): array
	{
		return $this->loadCriteriaByPrefix('CRM_BACKFILL_BASIC_CRITERIA', self::BASIC_CRITERIA_COUNT);
	}

	/**
	 * @return array<int, array{title: string, description: string}>
	 */
	private function loadCriteriaByPrefix(string $prefix, int $maxCriteria): array
	{
		$result = [];
		for ($i = 1; $i <= $maxCriteria; $i++)
		{
			$title = (string)Loc::getMessage("{$prefix}_{$i}_TITLE");
			$description = (string)Loc::getMessage("{$prefix}_{$i}_DESCRIPTION");
			if ($title === '' || $description === '')
			{
				break;
			}

			$result[] = [
				'title' => $title,
				'description' => $description,
			];
		}

		return $result;
	}

	private function isPromptUnchanged(CopilotCallAssessment $callAssessment): bool
	{
		return (new HintStateResolver())->isShippedReference(
			$callAssessment->getPrompt(),
			(string)$callAssessment->getCode(),
		);
	}
}
