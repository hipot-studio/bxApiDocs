<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Config\Feature as MobileFeature;
use Bitrix\Mobile\Feature\FeedbackRouterFeature;
use Bitrix\Mobile\Feature\SecuritySettingsFeature;
use Bitrix\Mobile\Feature\SelfDeleteV2Feature;
use Bitrix\Mobile\Feature\SettingsV2Feature;
use Bitrix\Mobile\Feature\SupportFeature;
use Bitrix\Mobile\Feature\TariffMenuDemoModeFeature;
use Bitrix\Mobile\Feature\TariffMenuFeature;
use Bitrix\Mobile\Feature\TariffMenuPurchaseCtaFeature;
use Bitrix\Mobile\Feature\WhatsNewFeature;
use Bitrix\Mobile\Public\Features\BitrixAnswersPersistentScreenFeature;
use Bitrix\Mobile\Public\Features\ManagedTabPresetsFeature;
use Bitrix\Mobile\Public\Features\TextEditorFeature;
use Bitrix\TasksMobile\Feature\ChecklistOnCompleteFeature;
use Bitrix\TasksMobile\Public\Features\TaskEmailFeature;
use Bitrix\TimemanMobile\Public\Features\WorkReportsFeature;
use Bitrix\TimemanMobile\Public\Features\WorkReportsWithAiFeature;

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
			'ChecklistOnCompleteFeature' => Loader::includeModule('tasksmobile')
				&& MobileFeature::isEnabled(ChecklistOnCompleteFeature::class),
			'ProjectsV2Feature' => Option::get('socialnetwork', 'new_projects', 'N') === 'Y',
			'SettingsV2Feature' => (new SettingsV2Feature())->isEnabled(),
			'SelfDeleteV2Feature' => (new SelfDeleteV2Feature())->isEnabled(),
			'SecuritySettingsFeature' => (new SecuritySettingsFeature())->isEnabled(),
			'SupportFeature' => (new SupportFeature())->isEnabled(),
			'FeedbackRouterFeature' => (new FeedbackRouterFeature())->isEnabled(),
			'WhatsNewFeature' => (new WhatsNewFeature())->isEnabled(),
			'DeveloperMenuEnabled' => Option::get('mobile', 'developers_menu_section', 'N') === 'Y',
			'WorkReportsFeature' => Loader::includeModule('timeman')
				&& Loader::includeModule('timemanmobile')
				&& MobileFeature::isEnabled(WorkReportsFeature::class),
			'ManagedTabPresetsFeature' => Feature::isEnabled(ManagedTabPresetsFeature::class),
			'WorkReportsWithAiFeature' => Loader::includeModule('timeman')
				&& Loader::includeModule('timemanmobile')
				&& MobileFeature::isEnabled(WorkReportsWithAiFeature::class),
			'TariffMenuFeature' => (new TariffMenuFeature())->isEnabled(),
			'TariffMenuPurchaseCtaFeature' => (new TariffMenuPurchaseCtaFeature())->isEnabled(),
			'TariffMenuDemoModeFeature' => (new TariffMenuDemoModeFeature())->isEnabled(),
			'TextEditorFeature' => Feature::isEnabled(TextEditorFeature::class),
			'TaskEmailFeature' => Loader::includeModule('tasksmobile')
				&& Feature::isEnabled(TaskEmailFeature::class),
			'BitrixAnswersPersistentScreenFeature' => Feature::isEnabled(BitrixAnswersPersistentScreenFeature::class),
		];
	}
}
