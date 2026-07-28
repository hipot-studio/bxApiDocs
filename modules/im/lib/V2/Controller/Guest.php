<?php

namespace Bitrix\Im\V2\Controller;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Controller\Filter\AuthorizationPrefilter;
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
			'checkSession' => [
				'-prefilters' => [
					Authentication::class,
					AuthorizationPrefilter::class,
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
		if (!Features::isChatWithGuestsAvailable(GuestService::getInstance()->getCurrentInviterId()))
		{
			$this->addError(new AuthError(AuthError::GUEST_FEATURE_DISABLED));

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
	 * Token is auto-wired from the request (cookie); missing token means invalid session.
	 *
	 * @restMethod im.v2.Guest.checkSession
	 */
	public function checkSessionAction(string $code, ?Token $token = null): array
	{
		$isValid = $token !== null
			&& GuestService::getInstance()->isGuestSessionValid($code, $token)
		;

		return ['isValid' => $isValid];
	}
}
