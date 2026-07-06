<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Auth;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\Application;
use Bitrix\Main\Web\Cookie;

/**
 * Service for guest user authorization.
 *
 * Handles Bitrix session authorization and cookie management for guest users.
 * Responsible for the "log in" step: creating a session and persisting auth cookies.
 *
 * @see AuthenticationService for token-based authentication (token → user lookup)
 * @see \Bitrix\Im\V2\Guest\GuestService for high-level guest operations
 */
class AuthorizationService
{
	public const COOKIE_AUTH = 'BITRIX_IM_GUEST_AUTH';
	public const COOKIE_HASH = 'BITRIX_IM_GUEST_HASH';

	protected static ?self $instance = null;

	private function __construct()
	{
	}

	public static function getInstance(): self
	{
		self::$instance ??= new self();

		return self::$instance;
	}

	/**
	 * Authorize guest user and set cookies.
	 *
	 * @param User $user Guest user to authorize
	 * @return Result
	 */
	public function authorize(User $user): Result
	{
		$result = new Result();

		if (!$user->isExist())
		{
			return $result->addError(new AuthError(AuthError::AUTHORIZE_ERROR));
		}

		if ($user->getExternalAuthId() !== UserGuest::AUTH_ID)
		{
			return $result->addError(new AuthError(AuthError::NOT_GUEST));
		}

		$globalUser = Locator::getContext()->getCUser();
		if ($globalUser === null)
		{
			return $result->addError(new AuthError(AuthError::AUTHORIZE_ERROR));
		}

		if ((int)$globalUser->GetID() === $user->getId())
		{
			return $result;
		}

		if (!$globalUser->Authorize($user->getId(), false, false, 'public'))
		{
			return $result->addError(new AuthError(AuthError::AUTHORIZE_ERROR));
		}

		$this->setCookies($globalUser->GetParam('XML_ID'));

		return $result;
	}

	/**
	 * Set auth cookies for the current response.
	 */
	protected function setCookies(string $xmlId): void
	{
		$context = Application::getInstance()->getContext();

		$cookie = new Cookie(self::COOKIE_AUTH, 'Y', null, false);
		$cookie->setHttpOnly(true);
		$context->getResponse()->addCookie($cookie);

		$hash = $this->extractHashFromXmlId($xmlId);
		if ($hash !== null)
		{
			$cookie = new Cookie(self::COOKIE_HASH, $hash, null, false);
			$cookie->setHttpOnly(true);
			$context->getResponse()->addCookie($cookie);
		}
	}

	protected function extractHashFromXmlId(string $xmlId): ?string
	{
		$prefix = UserGuest::AUTH_ID . '|';

		return str_starts_with($xmlId, $prefix) ? mb_substr($xmlId, mb_strlen($prefix)) : null;
	}
}
