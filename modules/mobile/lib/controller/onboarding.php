<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Mobile\Provider\UserRepository;
use Bitrix\Mobile\Profile\Tab\CommonTab;
use Bitrix\Mobile\Profile\ActionFilter\Attribute\CanView;
use Bitrix\Im\V2\Service\Locator;

final class Onboarding extends JsonController
{
	public function configureActions(): array
	{
		return [
			'isUserAlone' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	/**
	 * @restMethod mobile.Onboarding.isUserAlone
	 * @return bool
	 */
	public function isUserAloneAction(): bool
	{
		$userId = \Bitrix\Main\Engine\CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return false;
		}

		return UserRepository::isUserAlone();
	}

	/**
	 * @restMethod mobile.Onboarding.getProfileFields
	 * @param int $ownerId
	 * @return array|null
	 */
	#[CanView]
	public function getProfileFieldsAction(int $ownerId): ?array
	{
		if (!$ownerId)
		{
			return null;
		}

		return (new CommonTab($this->getCurrentUser()?->getId(), $ownerId))?->getCommonFields($ownerId) ?? [];
	}

	public function getMessagesAmountByChatIdAction(int $chatId): ?int
	{
		if (!$chatId || $chatId <= 0)
		{
			return null;
		}

		return Locator::getMessenger()->getChat($chatId)?->getMessageCount();
	}

	public function getUserQuantityByChatIdAction(int $chatId): ?int
	{
		if (!$chatId || $chatId <= 0)
		{
			return null;
		}

		return Locator::getMessenger()->getChat($chatId)?->getUserCount();
	}
}