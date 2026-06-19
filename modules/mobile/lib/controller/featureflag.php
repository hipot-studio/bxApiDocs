<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\SecuritySettingsFeature;
use Bitrix\Mobile\Feature\SettingsV2Feature;
use Bitrix\Mobile\Feature\SupportFeature;
use Bitrix\Mobile\Feature\WhatsNewFeature;
use Bitrix\TimemanMobile\Public\Features\WorkReportsFeature;

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
			'ProjectsV2Feature' => Option::get('socialnetwork', 'new_projects', 'N') === 'Y',
			'SettingsV2Feature' => (new SettingsV2Feature())->isEnabled(),
			'SecuritySettingsFeature' => (new SecuritySettingsFeature())->isEnabled(),
			'SupportFeature' => (new SupportFeature())->isEnabled(),
			'WhatsNewFeature' => (new WhatsNewFeature())->isEnabled(),
			'DeveloperMenuEnabled' => Option::get('mobile', 'developers_menu_section', 'N') === 'Y',
			'WorkReportsFeature' => Loader::includeModule('timeman')
				&& Loader::includeModule('timemanmobile')
				&& Feature::isEnabled(WorkReportsFeature::class),
		];
	}
}
