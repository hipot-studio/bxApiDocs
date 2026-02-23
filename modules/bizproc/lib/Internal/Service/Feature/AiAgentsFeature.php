<?php

namespace Bitrix\Bizproc\Internal\Service\Feature;

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
}
