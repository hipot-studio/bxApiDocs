<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\LoaderException;
use Bitrix\Mobile\Profile\ActionFilter\Attribute\CanUpdate;
use Bitrix\Mobile\Profile\ActionFilter\Attribute\CanView;
use Bitrix\Mobile\Profile\ActionFilter\Attribute\NewProfileEnabled;
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

	/**
	 * @restMethod mobile.Profile.getTabs
	 * @param int $ownerId
	 * @param string $selectedTabId
	 * @return array
	 */
	#[CloseSession]
	#[NewProfileEnabled]
	public function getTabsAction(int $ownerId, string $selectedTabId): array
	{
		$provider = new \Bitrix\Mobile\Profile\Provider\ProfileProvider(
			$this->getCurrentUser()?->getId(),
			$ownerId,
		);

		return $provider->getTabs($selectedTabId);
	}

	/**
	 * @restMethod mobile.Profile.getGratitudeList
	 * @param int $ownerId
	 * @param PageNavigation|null $pageNavigation
	 * @return array
	 * @throws LoaderException
	 */
	#[CloseSession]
	#[NewProfileEnabled]
	#[CanView]
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

	/**
	 * @restMethod mobile.Profile.save
	 * @param int $ownerId
	 * @param array{tags: string[]} $fieldsToSave
	 * @return array
	 */
	#[NewProfileEnabled]
	#[CanUpdate]
	public function saveAction(int $ownerId, array $fieldsToSave): array
	{
		$provider = new \Bitrix\Mobile\Profile\Provider\ProfileProvider(
			$this->getCurrentUser()?->getId(),
			$ownerId,
		);

		return $provider->save($fieldsToSave);
	}


	/**
	 * @restMethod mobile.Profile.searchTags
	 * @param int $ownerId
	 * @param int $limit
	 * @param string $searchString
	 * @return array
	 */
	#[CloseSession]
	#[NewProfileEnabled]
	#[CanUpdate]
	public function searchTagsAction(int $ownerId, int $limit = 20, string $searchString = ''): array
	{
		$provider = new \Bitrix\Mobile\Profile\Provider\TagProvider();

		return $provider->searchTags($ownerId, $limit, $searchString);
	}

	/**
	 * @restMethod mobile.Profile.addTag
	 * @param int $ownerId
	 * @param string $tag
	 * @return array
	 */
	#[NewProfileEnabled]
	#[CanUpdate]
	public function addTagAction(int $ownerId, string $tag): array
	{
		$provider = new \Bitrix\Mobile\Profile\Provider\TagProvider();

		return $provider->addTag($ownerId, $tag);
	}
}