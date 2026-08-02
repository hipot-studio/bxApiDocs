<?php

namespace Bitrix\Bizproc\Internal\Service\Feature;

use Bitrix\Bizproc\Api\Enum\Template\CreateSource;
use Bitrix\Bizproc\Internal\Service\Tariff\TariffChecker;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;

final class AiAgentsFeature extends BaseFeature
{
	public function getFeatureName(): string
	{
		return 'crm_automation_designer';
	}

	public function getErrorCode(): string
	{
		return 'AI_AGENTS_UNAVAILABLE_BY_TARIFF';
	}

	public function getTariffSliderCode(): string
	{
		return 'limit_v2_bizproc_ai_agents_start';
	}

	public function isRestartAvailable(int $templateId): bool
	{
		if ($this->isAvailable())
		{
			return true;
		}

		if (!TariffChecker::isBasicOrHigher())
		{
			return false;
		}

		$row = WorkflowTemplateTable::getList([
			'select' => ['CREATE_SOURCE'],
			'filter' => ['=ID' => $templateId],
		])->fetch();

		return ($row['CREATE_SOURCE'] ?? null) === CreateSource::Scenario->value;
	}
}
