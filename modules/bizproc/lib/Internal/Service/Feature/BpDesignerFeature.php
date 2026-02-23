<?php

namespace Bitrix\Bizproc\Internal\Service\Feature;

final class BpDesignerFeature extends BaseFeature
{
	public function getFeatureName(): string
	{
		return 'crm_automation_designer';
	}

	public function getTariffSliderCode(): string
	{
		return '';
	}

	public function getErrorCode(): string
	{
		return 'BP_DESIGNER_UNAVAILABLE_BY_TARIFF';
	}

}
