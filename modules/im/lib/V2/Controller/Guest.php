<?php

namespace Bitrix\Im\V2\Controller;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Controller\Filter\AuthorizationPrefilter;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Guest\Auth\AuthError;
use Bitrix\Im\V2\Guest\Auth\Token;
use Bitrix\Im\V2\Guest\GuestService;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Engine\AutoWire\Parameter;
use Bitrix\Main\Engine\ActionFilter\Authentication;

class Guest extends BaseController
{
	public function getAutoWiredParameters(): array
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
	 * Token is auto-wired from the request (cookie); a missing token means an invalid session.
	 * Returns the validity flag and, when invalid, the reason as an error code (empty when valid).
	 *
	 * @restMethod im.v2.Guest.checkSession
	 */
	public function checkSessionAction(string $code, ?Token $token = null): array
	{
		$result = $token === null
			? (new Result())->addError(new AuthError(AuthError::GUEST_NOT_FOUND))
			: GuestService::getInstance()->validateGuestSession($code, $token)
		;

		return [
			'isValid' => $result->isSuccess(),
			'reason' => $result->isSuccess() ? '' : ($result->getErrors()[0]?->getCode() ?? ''),
		];
	}
}
