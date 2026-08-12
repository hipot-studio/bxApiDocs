<?php declare(strict_types=1);

namespace Bitrix\Intranet\Integration\Ai;

use Bitrix\AI\Handler\AgreementHandler;
use Bitrix\Main\Loader;

class Wizard
{
	public static function applyNewPortalDefaults(): void
	{
		if (
			!Loader::includeModule('ai')
			|| !method_exists(AgreementHandler::class, 'onPresetApplyDisableScenarios')
		)
		{
			return;
		}

		AgreementHandler::onPresetApplyDisableScenarios();
	}
}
