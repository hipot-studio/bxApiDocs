<?php

declare(strict_types=1);

namespace Bitrix\AI\Handler;

use Bitrix\AI\Services\BitrixGptAgreementService;

class AgreementHandler
{
	/** @see BitrixGptAgreementService */
	public static function onPresetApplyDisableScenarios(): void
	{
		$service = new BitrixGptAgreementService();
		if (!$service->isOldPortal() && !$service->isAvailableForExternalRequest())
		{
			$service->disableAllScenarios();
		}
	}
}
