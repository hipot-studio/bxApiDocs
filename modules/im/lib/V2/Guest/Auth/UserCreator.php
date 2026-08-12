<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Auth;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security\Random;

/**
 * Creates temporary guest users in the database.
 */
class UserCreator
{
	protected const NAME_MAX_LENGTH = 50;

	/**
	 * Create a new guest user.
	 *
	 * @param string|null $name Optional display name
	 * @return AuthResult
	 */
	public function create(?string $name): AuthResult
	{
		$result = new AuthResult();

		$hash = $this->generateHash();
		$fields = $this->prepareUserFields($name, $hash);

		$userManager = new \CUser();
		$userId = $userManager->Add($fields);

		if (!$userId)
		{
			return $result->addError(new AuthError(AuthError::GUEST_REGISTRATION_ERROR));
		}

		return $result
			->setToken($hash)
			->setUser(User::getInstance((int)$userId))
		;
	}

	/**
	 * Generate a cryptographically secure 32-character alphanumeric hash.
	 */
	protected function generateHash(): string
	{
		return Random::getString(32, true);
	}

	/**
	 * Prepare fields for user creation.
	 *
	 * Note: PASSWORD is not required for external users (EXTERNAL_AUTH_ID is set).
	 * LOGIN must be unique due to DB constraint, but guest authenticates via token only.
	 */
	protected function prepareUserFields(?string $name, string $hash): array
	{
		return [
			'LOGIN' => UserGuest::AUTH_ID . '_' . Random::getString(16, true),
			'NAME' => $this->sanitizeName($name) ?: Loc::getMessage('IM_GUEST_USER_DEFAULT_NAME'),
			'EXTERNAL_AUTH_ID' => UserGuest::AUTH_ID,
			'XML_ID' => AuthenticationService::getInstance()->buildXmlId($hash),
			'ACTIVE' => 'Y',
		];
	}

	/**
	 * Sanitize and validate guest name.
	 */
	public function sanitizeName(?string $name): ?string
	{
		if ($name === null)
		{
			return null;
		}

		$sanitized = trim(strip_tags($name));
		if ($sanitized === '')
		{
			return null;
		}

		return mb_substr($sanitized, 0, self::NAME_MAX_LENGTH);
	}
}
