<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\JsonController;

final class FeatureFlag extends JsonController
{
	public function configureActions(): array
	{
		return [
			'getFeatureFlags' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	/**
	 * @restMethod mobile.FeatureFlag.getFeatureFlags
	 */
	public function getFeatureFlagsAction(): array
	{
		return [
			'SettingsV2Feature' => (new \Bitrix\Mobile\Feature\SettingsV2Feature())->isEnabled(),
			'SecuritySettingsFeature' => (new \Bitrix\Mobile\Feature\SecuritySettingsFeature())->isEnabled(),
			'SupportFeature' => (new \Bitrix\Mobile\Feature\SupportFeature())->isEnabled(),
			'WhatsNewFeature' => (new \Bitrix\Mobile\Feature\WhatsNewFeature())->isEnabled(),
			'DeveloperMenuEnabled' => \Bitrix\Main\Config\Option::get('mobile', 'developers_menu_section', 'N') === 'Y',
		];
	}
}
