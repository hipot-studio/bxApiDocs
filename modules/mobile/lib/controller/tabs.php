<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Error;
use Bitrix\Mobile\AppTabs\Menu;
use Bitrix\Mobile\AppTabs\MenuNew;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\MenuFeature;
use Bitrix\Mobile\Context;
use Bitrix\Mobile\Tab\Preset\Service\LegacyTabPresetCompatibilityService;

class Tabs extends \Bitrix\Main\Engine\Controller
{
	public function setConfigAction(array $config = [])
	{
		$tabPresetService = LegacyTabPresetCompatibilityService::forContext(new Context());

		return $tabPresetService->setConfig($config);
	}

	public function getDataAction()
	{
		$tabPresetService = LegacyTabPresetCompatibilityService::forContext(new Context());
		$manager = $tabPresetService->getManager();
		$resolvedState = $tabPresetService->getResolvedState();
		$result = [
			'presets' => [
				'current' => $resolvedState['currentPresetName'],
				'list' => $manager->getPresetList(),
			],
			'tabs' => [
				'list' => $manager->getAllTabIDs(),
			],
		];

		$activeTabs = $resolvedState['activeTabs'];
		$result['tabs']['current'] = array_reduce(
			array_keys($activeTabs),
			static function ($result, $tabId) use ($manager, $activeTabs) {
				$tabInstance = $manager->getTabInstance($tabId);
				$result[$tabId] = [
					'sort' => $activeTabs[$tabId],
					'title' => $tabInstance->getTitle(),
					'canBeRemoved' => $tabInstance->canBeRemoved(),
					'canChangeSort' => $tabInstance->canChangeSort(),
					'iconId' => $tabInstance->getIconId(),
				];

				return $result;
			}, []);

		$result['tabs']['list'] = array_reduce(
			$manager->getAllTabIDs(),
			static function ($result, $tabId) use ($manager)
			{
				$instance = $manager->getTabInstance($tabId);

				$result[$tabId] = [
					'title' => $instance->getTitle(),
					'shortTitle' => $instance->getShortTitle(),
					'iconId' => $instance->getIconId(),
				];

				$isMenuFeatureEnabled = Feature::isEnabled(MenuFeature::class);

				$menuId = $isMenuFeatureEnabled ? (new MenuNew())->getId() : (new Menu())->getId();

				if ($tabId === $menuId)
				{
					if ($isMenuFeatureEnabled)
					{
						$result[$tabId]['imageUrl'] = $instance->getImageUrl();
						$result[$tabId]['name'] = $instance->getLastAndSecondName();
					}

					$result[$tabId]['isAvatarEnabled'] = $isMenuFeatureEnabled;
				}

				return $result;
			}, []);

		return $result;
	}

	/**
	 * @throws ArgumentNullException
	 * @throws ArgumentOutOfRangeException
	 */
	public function getCurrentPresetNameAction(): ?string
	{
		return LegacyTabPresetCompatibilityService::forContext(new Context())->getCurrentPresetName();
	}

	/**
	 * @throws ArgumentNullException
	 * @throws ArgumentOutOfRangeException
	 */
	public function getCurrentPresetItemsAction(): array
	{
		$tabPresetService = LegacyTabPresetCompatibilityService::forContext(new Context());
		$manager = $tabPresetService->getManager();
		$activeTabs = $tabPresetService->getActiveTabs();

		if (!$activeTabs || !is_array($activeTabs))
		{
			return [];
		}

		asort($activeTabs);

		$result = [];
		foreach ($activeTabs as $tabId => $sort)
		{
			$tabInstance = $manager->getTabInstance($tabId);
			$result[$tabId] = [
				'sort' => $sort,
				'badgeCode' => $tabInstance?->getData()['badgeCode'] ?? null,
			];
		}

		return $result;
	}

	public function setPresetAction($name): ?array
	{
		$result = LegacyTabPresetCompatibilityService::forContext(new Context())->setPreset($name);

		if ($result === null)
		{
			$this->addError(new Error('Preset not found', 404));
		}

		return $result;
	}
}
