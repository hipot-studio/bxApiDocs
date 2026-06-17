<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Im;

use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Entity\User;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class ChatService
{
	public const ERROR_NO_ACTIVE_USER = 'ERROR_NO_ACTIVE_USER';

	/**
	 * @param UserCollection $userCollection
	 * @throws LoaderException
	 * @throws ArgumentException
	 * @return Result
	 */
	public function createGroupChatWithUsers(UserCollection $userCollection): Result
	{
		if (!Loader::includeModule('im'))
		{
			throw new LoaderException('Module im is not installed');
		}

		$userCollection = $userCollection->filter(
			fn (User $user) => $user->getActive(),
		);

		if ($userCollection->empty())
		{
			$error = new Error(Loc::getMessage('INTRANET_INTEGRATION_CREATE_CHAT_ERROR_NO_ACTIVE_USER'), self::ERROR_NO_ACTIVE_USER);

			return (new Result())->addError($error);
		}

		$messenger = \Bitrix\Im\V2\Service\Locator::getMessenger();

		return $messenger->createChat([
			'USERS' => $userCollection->getIds(),
		]);
	}
}
