<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Auth;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\UserTable;

/**
 * Service for guest user authentication (token-based).
 *
 * Handles the "who are you?" step: validates tokens and finds users by token.
 *
 * @see AuthorizationService for session authorization (session + cookies)
 * @see \Bitrix\Im\V2\Guest\GuestService for high-level guest operations
 */
class AuthenticationService
{
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
	 * Authenticate guest by token.
	 *
	 * Expects a validated token (from Token value object).
	 * If the user is not yet authorized, finds them by token and delegates
	 * to AuthorizationService. If already authorized, verifies the token matches.
	 *
	 * @param Token $token Validated guest token
	 * @return AuthResult
	 */
	public function authenticate(Token $token): AuthResult
	{
		$result = new AuthResult();

		$globalUser = Locator::getContext()->getCUser();
		if ($globalUser === null)
		{
			return $result->addError(new AuthError(AuthError::AUTHORIZE_ERROR));
		}

		if ($globalUser->IsAuthorized())
		{
			return $this->verifyAuthorizedGuest($token, $globalUser, $result);
		}

		return $this->authenticateByToken($token, $result);
	}

	/**
	 * Find user ID by token.
	 *
	 * @return int|null User ID or null if not found
	 */
	public function findUserByToken(Token $token): ?int
	{
		$userData = UserTable::query()
			->setSelect(['ID', 'EXTERNAL_AUTH_ID'])
			->where('XML_ID', $this->buildXmlId($token->getValue()))
			->fetch()
		;

		if ($userData && $userData['EXTERNAL_AUTH_ID'] === UserGuest::AUTH_ID)
		{
			return (int)$userData['ID'];
		}

		return null;
	}

	/**
	 * Build XML_ID value from token.
	 */
	public function buildXmlId(string $token): string
	{
		return UserGuest::AUTH_ID . '|' . $token;
	}

	/**
	 * Verify that the currently authorized user matches the provided token.
	 */
	protected function verifyAuthorizedGuest(Token $token, \CUser $globalUser, AuthResult $result): AuthResult
	{
		if ($globalUser->GetParam('EXTERNAL_AUTH_ID') !== UserGuest::AUTH_ID)
		{
			return $result->addError(new AuthError(AuthError::NOT_GUEST));
		}

		if ($globalUser->GetParam('XML_ID') !== $this->buildXmlId($token->getValue()))
		{
			return $result->addError(new AuthError(AuthError::DIFFERENT_GUEST));
		}

		return $result
			->setUser(User::getInstance((int)$globalUser->GetID()))
			->setToken($token->getValue())
		;
	}

	/**
	 * Find user by token and authorize via AuthorizationService.
	 */
	protected function authenticateByToken(Token $token, AuthResult $result): AuthResult
	{
		$userId = $this->findUserByToken($token);
		if ($userId === null)
		{
			return $result->addError(new AuthError(AuthError::USER_NOT_FOUND));
		}

		$user = User::getInstance($userId);
		$authResult = AuthorizationService::getInstance()->authorize($user);
		if (!$authResult->isSuccess())
		{
			return $result->addErrors($authResult->getErrors());
		}

		return $result
			->setUser($user)
			->setToken($token->getValue())
		;
	}
}
