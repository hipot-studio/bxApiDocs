<?php

namespace Bitrix\Sign\Integration\Ui\EntitySelector;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\EO_User;
use Bitrix\Main\EO_User_Collection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Socialnetwork;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\Tab;
use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Factory\Access\AccessibleItemFactory;
use Bitrix\Sign\Service\Container;

if (!Loader::includeModule('socialnetwork'))
{
	return;
}

/**
 * @see Socialnetwork\Integration\UI\EntitySelector\FiredUserProvider
 */
class FiredUserProvider extends Socialnetwork\Integration\UI\EntitySelector\UserProvider
{
	private AccessController $accessController;
	private AccessibleItemFactory $accessibleItemFactory;

	protected const ENTITY_ID = 'sign-fired-user';

	public function __construct(array $options = [])
	{
		parent::__construct($options);

		$currentUser = (int)CurrentUser::get()->getId();

		if ($currentUser <= 0)
		{
			throw new SystemException('The user must be authorized to use sign-fired-user provider');
		}

		$this->accessController = new AccessController($currentUser);
		$this->accessibleItemFactory = Container::instance()->getAccessibleItemFactory();
	}

	public function isAvailable(): bool
	{
		if (!$this->accessController->check(ActionDictionary::ACTION_B2E_MY_SAFE_FIRED))
		{
			return false;
		}

		return parent::isAvailable();
	}

	protected function prepareOptions(array $options = []): void
	{
		parent::prepareOptions($options);
		$this->options['activeUsers'] = false;
	}

	protected function getPreloadedUsersCollection(): EO_User_Collection
	{
		return $this->getUserCollection([
			'order' => [
				'LAST_ACTIVITY_DATE' => 'desc',
			],
			'limit' => self::MAX_USERS_IN_RECENT_TAB,
		]);
	}

	public function handleBeforeItemSave(Item $item): void
	{
		// exclude fired users from the recent tab
		$item->setSaveable(false);
	}

	public function fillDialog(Dialog $dialog): void
	{
		parent::fillDialog($dialog);

		if ($this->shouldShowFiredUsersTab($dialog))
		{
			$firedTab = $this->createTab();
			$dialog->addTab($firedTab);
		}
	}

	public static function makeItem(EO_User $user, array $options = []): Item
	{
		$item = parent::makeItem($user, $options);

		// exclude fired users from the recent tab
		$item->setAvailableInRecentTab(false);

		return $item;
	}

	private function shouldShowFiredUsersTab(Dialog $dialog): bool
	{
		return count($dialog->getItemCollection()->getEntityItems(self::ENTITY_ID)) > 0;
	}

	private function createTab(): Tab
	{
		return new Tab([
			'id' => self::ENTITY_ID,
			'title' => Loc::getMessage('SIGN_ENTITY_SELECTOR_FIREDUSER_TAB_TITLE'),
			'stub' => true,
			'icon' => [
				'default' => 'o-department',
				'selected' => 's-department',
			],
		]);
	}
}
