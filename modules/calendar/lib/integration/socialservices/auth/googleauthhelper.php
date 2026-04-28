<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Integration\Socialservices\Auth;

use Bitrix\Calendar\Internal\Integration\Socialservices\Auth\AbstractAuthService;
use Bitrix\Calendar\Synchronization\Internal\Exception\LogicException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Repository\RepositoryReadException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\AuthorizationException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\NotAuthorizedException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use CGoogleOAuthInterface;

class GoogleAuthHelper extends AbstractAuthService
{
	/**
	 * @param int $userId
	 *
	 * @return CGoogleOAuthInterface
	 *
	 * @throws RepositoryReadException
	 * @throws AuthorizationException
	 * @throws NotAuthorizedException
	 */
	public static function getUserAuthEntity(int $userId): CGoogleOAuthInterface
	{
		if (!Loader::includeModule('socialservices'))
		{
			throw new LogicException('The module "socialservices" did not loaded but user is authorized');
		}

		if (\CSocServGoogleProxyOAuth::isProxyAuth())
		{
			$auth = new \CSocServGoogleProxyOAuth($userId);
		}
		else
		{
			$auth = new \CSocServGoogleOAuth($userId);
		}

		$authEntity = $auth->getEntityOAuth();

		$authEntity->addScope([
			'https://www.googleapis.com/auth/calendar',
			'https://www.googleapis.com/auth/calendar.readonly',
		]);
		$authEntity->removeScope('https://www.googleapis.com/auth/drive');

		$authEntity->setUser($userId);

		$authEntity->GetAccessToken();

		if (!$authEntity->GetAccessToken())
		{
			throw new AuthorizationException(
				sprintf('Unable to get Google OAuth token for user %d', $userId),
				401,
			);
		}

		return $authEntity;
	}

	/**
	 * @throws RepositoryReadException
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	public static function getStoredTokens(int $userId): ?array
	{
		/** @noinspection PhpUnhandledExceptionInspection */
		return ServiceLocator::getInstance()
			->get(GoogleAuthHelper::class)
			->getUserTokens($userId, 'GoogleOAuth')
		;
	}
}
