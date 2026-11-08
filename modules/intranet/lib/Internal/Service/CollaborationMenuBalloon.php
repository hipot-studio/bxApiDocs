<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service;

use Bitrix\Main\Config\Option;
use Bitrix\Intranet\Internal\Integration\Bitrix24\CollaborationWebMenu;
use Bitrix\Intranet\CurrentUser as IntranetCurrentUser;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Promotion\Promotion;
use Bitrix\Im\V2\Promotion\Service\UIPromotionService;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

class CollaborationMenuBalloon
{
	public const ACTIVATION_TIMESTAMP_OPTION = 'collaboration_menu_balloon_activated_at';

	public static function getDecisionForCurrentRequest(?bool $onMessengerPage = null): ?string
	{
		if (
			!CollaborationWebMenu::isEnabled()
			|| ($activationTimestamp = self::getActivationTimestamp()) === null
			|| ($userId = (int)CurrentUser::get()->getId()) <= 0
			|| \CUserOptions::GetOption('intranet', 'collaboration_menu_balloon_processed', null, $userId) === 'Y'
			|| !(($registration = IntranetCurrentUser::get()->getDateRegister()) instanceof DateTime)
			|| $registration->getTimestamp() <= 0
		)
		{
			return null;
		}

		if ($registration->getTimestamp() >= $activationTimestamp)
		{
			return 'mark';
		}

		if (self::isFolderTourPending($userId, $onMessengerPage))
		{
			return null;
		}

		return 'show';
	}

	private static function isFolderTourPending(int $userId, ?bool $onMessengerPage): bool
	{
		$onMessengerPage ??= (bool)preg_match(
			'~(?:^|/)online(?:/|$)~',
			Application::getInstance()->getContext()->getRequest()->getRequestedPage(),
		);
		if (!$onMessengerPage || !Loader::includeModule('im'))
		{
			return false;
		}

		if (
			!Features::isChatFoldersWebAvailableForUser($userId)
			|| !Features::isChatFolderAllAvailable()
			|| Promotion::isUnavailable()
		)
		{
			return false;
		}

		$tour = UIPromotionService::getInstance()->getTourById('im:folder-all-onboarding:12082026:all');

		return $tour ? $tour->isAvailable($userId) : false;
	}

	public static function getActivationTimestamp(): ?int
	{
		$value = Option::get('intranet', self::ACTIVATION_TIMESTAMP_OPTION, '');

		if (preg_match('/^[0-9]+$/D', $value) !== 1 || (int)$value <= 0)
		{
			return null;
		}

		return (int)$value;
	}
}
