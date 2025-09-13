<?php

namespace Bitrix\Mobile\Profile\Provider;

use Bitrix\Main\Config\Option;
use Bitrix\Mobile\Profile\Enum\TabType;
use Bitrix\Mobile\Profile\Tab\ProfileTabFactory;

class ProfileProvider
{
	private int $viewerId;
	private int $ownerId;

	public function __construct(
		int $viewerId,
		int $ownerId,
	)
	{
		$this->viewerId = $viewerId;
		$this->ownerId = $ownerId;
	}

	/**
	 * @param string $selectedTabId
	 * @return array
	 */
	public function getTabs(string $selectedTabId): array
	{
		$availableTabs = $this->getAvailableTabs();

		$selectedTabNotAvailable = empty(array_filter($availableTabs, function ($tab) use ($selectedTabId) {
			return $tab['id'] === $selectedTabId;
		}));

		if ($selectedTabNotAvailable && !empty($availableTabs))
		{
			$selectedTabId = $availableTabs[0]['id'];
		}

		return [
			'tabs' => $availableTabs,
			'selectedTabId' => $selectedTabId,
		];
	}

	private function getAvailableTabs(): array
	{
		$availableTabs = [];
		$tabInstances = $this->getTabInstances();
		foreach ($tabInstances as $tab)
		{
			if ($tab->isAvailable())
			{
				$tabInfo = [
					'id' => $tab->getType()->value,
					'title' => $tab->getTitle(),
					'params' => $tab->getParams(),
				];

				if ($tab->isComponent())
				{
					$tabInfo['componentName'] = $tab->getComponentName();
					$tabInfo['component'] = $tab->getComponent();
				}
				else if ($tab->isWidget())
				{
					$tabInfo['widget'] = $tab->getWidget();
				}

				$availableTabs[] = $tabInfo;
			}
		}

		return $availableTabs;
	}

	/**
	 * @return \Bitrix\Mobile\Profile\Tab\BaseProfileTab[]
	 */
	private function getTabInstances(): array
	{
		return ProfileTabFactory::createTabs($this->viewerId, $this->ownerId);
	}

	/**
	 * @param TabType $tabType
	 * @return array
	 */
	public function getTabData(TabType $tabType): array
	{
		return ProfileTabFactory::createTab($tabType, $this->viewerId, $this->ownerId)->getData();
	}

	/**
	 * @return bool
	 */
	public static function isNewProfileFeatureEnabled(): bool
	{
		return Option::get('mobile', 'profile_feature_enabled', 'N') === 'Y';
	}
}
