<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\LoaderException;
use Bitrix\Mobile\Profile\ActionFilter\ProfileAccess;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Mobile\Profile\Enum\TabType;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Mobile\Trait\PublicErrorsTrait;

final class Profile extends JsonController
{
	use PublicErrorsTrait;

	/**
	 * @throws BinderArgumentException
	 */
	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				TabType::class,
				'tabType',
				function($className, string $tabType) {
					return TabType::from($tabType);
				}
			),
		];
	}

	protected function getQueryActionNames(): array
	{
		return [
			'getTabs',
			'getTabData',
			'getGratitudeList',
			'isNewProfileFeatureEnabled',
		];
	}

	/**
	 * @restMethod mobile.Profile.getTabs
	 * @param int $ownerId
	 * @param string $selectedTabId
	 * @return array
	 */
	#[CloseSession]
	#[ProfileAccess]
	public function getTabsAction(int $ownerId, string $selectedTabId): array
	{
		$provider = new \Bitrix\Mobile\Profile\Provider\ProfileProvider(
			$this->getCurrentUser()?->getId(),
			$ownerId,
		);

		return $provider->getTabs($selectedTabId);
	}

	/**
	 * @restMethod mobile.Profile.getTabData
	 * @param TabType $tabType
	 * @param int $ownerId
	 * @return array
	 */
	#[CloseSession]
	#[ProfileAccess]
	public function getTabDataAction(TabType $tabType, int $ownerId): array
	{
		$provider = new \Bitrix\Mobile\Profile\Provider\ProfileProvider(
			$this->getCurrentUser()?->getId(),
			$ownerId,
		);

		return $provider->getTabData($tabType);
	}

	/**
	 * @restMethod mobile.Profile.getGratitudeList
	 * @param int $ownerId
	 * @param PageNavigation|null $pageNavigation
	 * @return array
	 * @throws LoaderException
	 */
	#[CloseSession]
	#[ProfileAccess]
	public function getGratitudeListAction(
		int $ownerId,
		?PageNavigation $pageNavigation = null,
	): array
	{
		if (!$ownerId)
		{
			return [];
		}

		$provider = new \Bitrix\Mobile\Profile\Provider\GratitudeProvider();

		return $provider->getListItems($ownerId, $pageNavigation);
	}

	/**
	 * @restMethod mobile.Profile.isNewProfileFeatureEnabled
	 * @return bool
	 */
	#[CloseSession]
	public function isNewProfileFeatureEnabledAction(): bool
	{
		return \Bitrix\Mobile\Profile\Provider\ProfileProvider::isNewProfileFeatureEnabled();
	}
}