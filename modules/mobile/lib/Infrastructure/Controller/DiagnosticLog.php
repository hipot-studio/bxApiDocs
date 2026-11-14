<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Infrastructure\Controller;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Mobile\Public\Features\DiagnosticLogFeature;

final class DiagnosticLog extends JsonController
{
	public function configureActions(): array
	{
		return [
			'getSettings' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	/**
	 * Returns the settings of the diagnostic log for the current user.
	 *
	 * @restMethod mobile.v2.DiagnosticLog.getSettings
	 * @return array{isEnabled: bool}
	 */
	public function getSettingsAction(): array
	{
		return [
			'isEnabled' => Feature::isEnabled(DiagnosticLogFeature::class),
		];
	}
}
