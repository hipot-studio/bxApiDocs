<?php

namespace Bitrix\Im\V2\Controller;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Guest\Auth\AuthError;
use Bitrix\Im\V2\Guest\Auth\Token;
use Bitrix\Im\V2\Guest\GuestService;
use Bitrix\Main\Engine\AutoWire\Parameter;
use Bitrix\Main\Engine\ActionFilter\Authentication;

class Guest extends BaseController
{
	public function getAutoWiredParameters()
	{
		return array_merge(parent::getAutoWiredParameters(), [
			new Parameter(
				Token::class,
				function (): ?Token {
					return Token::createFromRequest();
				}
			),
		]);
	}

	public function configureActions(): array
	{
		return [
			'joinByCode' => [
				'-prefilters' => [
					Authentication::class,
				],
			],
		];
	}

	/**
	 * Update the display name of the current guest user.
	 *
	 * @restMethod im.v2.Guest.setName
	 */
	public function setNameAction(string $name): ?array
	{
		if (!Features::isChatWithGuestsAvailable())
		{
			$this->addError(new AuthError(AuthError::FEATURE_DISABLED));

			return null;
		}

		$result = GuestService::getInstance()->setName($name);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$userId = \Bitrix\Im\V2\Service\Locator::getContext()->getUserId();

		return [
			'user' => User::getInstance($userId)->toRestFormat(),
		];
	}

	/**
	 * Main entry point for guests.
	 * Handles user creation, authentication, and joining the chat via invite code.
	 *
	 * @restMethod im.v2.Guest.joinByCode
	 */
	public function joinByCodeAction(string $code, ?Token $token = null, ?string $name = null): ?array
	{
		if (!Features::isChatWithGuestsAvailable())
		{
			$this->addError(new AuthError(AuthError::FEATURE_DISABLED));

			return null;
		}

		$result = GuestService::getInstance()->joinByCode($code, $token, $name);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'token' => $result->getToken(),
			'user' => $result->getUser()->toRestFormat(),
			'chatId' => $result->getChat()->getChatId(),
			'chatTitle' => $result->getChat()->getTitle(),
		];
	}
}
