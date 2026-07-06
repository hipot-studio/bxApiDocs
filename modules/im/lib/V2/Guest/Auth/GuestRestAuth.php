<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Auth;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Service\Locator;

/**
 * Handler for old REST API authentication (via rest:onRestCheckAuth event).
 *
 * Provides authentication for old REST methods (im.message.add, im.chat.get, etc.)
 * called by mobile app and legacy widgets.
 *
 * Must run BEFORE SessionAuth (lower SORT value) to prevent SessionAuth from rejecting
 * guest users, since SessionAuth blocks all external user types in isAccessAllowed().
 *
 * For new REST controllers (im.v2.*), guest auth is handled by AuthorizationPrefilter.
 *
 * @see \Bitrix\Im\V2\Controller\Filter\AuthorizationPrefilter for controller-based authentication
 * @see AuthenticationService for token-based authentication logic
 * @see AuthorizationService for session authorization
 */
class GuestRestAuth
{
	/** @var list<string>|null */
	private static ?array $allowedMethodsLowerCache = null;

	/**
	 * REST methods allowed for guest users.
	 * V2 controller methods (im.v2.*) are not listed here — they are handled
	 * by AuthorizationPrefilter's allowedGuestControllers whitelist.
	 */
	protected const ALLOWED_METHODS = [
		'server.time',
		'smile.get',

		// Pull — required for realtime updates
		'pull.config.get',
		'pull.watch.extend',

		// Chat
		'im.chat.get',
		'im.chat.user.list',

		// Messages
		'im.message.add',
		'im.message.update',
		'im.message.delete',
		'im.message.like',

		// Dialog
		'im.dialog.messages.get',
		'im.dialog.read',
		'im.dialog.writing',
		'im.dialog.users.list',

		// Recent
		'im.recent.get',
		'im.recent.list',

		// Notifications
		'im.notify.get',
		'im.notify.schema.get',

		// Counters
		'im.counters.get',

		// User
		'im.user.get',

		// Disk
		'im.disk.folder.get',
		'im.disk.folder.list.get',
		'im.disk.file.commit',
		'disk.folder.uploadfile',

		// Sidebar
		'im.chat.favorite.get',
		'im.chat.favorite.counter.get',
		'im.chat.url.get',
		'im.chat.url.counter.get',
		'im.chat.file.collection.get',
		'im.chat.task.get',
		'im.chat.calendar.get',
	];

	/**
	 * Handler for rest:onRestCheckAuth event.
	 *
	 * Activates in two cases:
	 * 1. Guest token found in request (header/cookie via Token::createFromRequest)
	 * 2. User already authorized via session as im_guest (intercepts before SessionAuth rejects)
	 *
	 * @param array $query Request query parameters
	 * @param mixed $scope Requested scope
	 * @param mixed &$res Result reference
	 * @return bool|null null = not handled, true = success, false = error
	 */
	public static function onRestCheckAuth(array $query, $scope, &$res): ?bool
	{
		if (!Features::isChatWithGuestsAvailable())
		{
			return null;
		}

		$token = Token::createFromRequest();

		$globalUser = Locator::getContext()->getCUser();
		if ($globalUser === null)
		{
			return null;
		}

		$isSessionGuest = $globalUser->IsAuthorized()
			&& $globalUser->GetParam('EXTERNAL_AUTH_ID') === UserGuest::AUTH_ID
		;

		if ($token === null && !$isSessionGuest)
		{
			return null;
		}

		if (!self::isMethodAllowed())
		{
			$res = self::buildErrorResult(
				AuthError::METHOD_NOT_ALLOWED,
				'IM Guest: method not allowed for guest users'
			);

			return false;
		}

		if ($isSessionGuest && $token === null)
		{
			$userId = (int)$globalUser->GetID();
			\CUser::SetLastActivityDate($userId, true);

			$res = self::buildSuccessResult($userId);

			return true;
		}

		return self::authenticateByToken($token, $res);
	}

	private static function authenticateByToken(Token $token, &$res): ?bool
	{
		$authResult = AuthenticationService::getInstance()->authenticate($token);

		if (!$authResult->isSuccess())
		{
			return self::handleAuthError($authResult, $res);
		}

		$userId = $authResult->getUser()->getId();
		\CUser::SetLastActivityDate($userId, true);

		$res = self::buildSuccessResult($userId);

		return true;
	}

	private static function handleAuthError(AuthResult $authResult, &$res): ?bool
	{
		$error = $authResult->getErrors()[0] ?? null;
		$errorCode = $error?->getCode() ?? AuthError::AUTHORIZE_ERROR;

		// NOT_GUEST — regular portal user, pass to next auth handler
		if ($errorCode === AuthError::NOT_GUEST)
		{
			return null;
		}

		$res = self::buildErrorResult($errorCode, 'IM Guest: authentication failed');

		return false;
	}

	private static function isMethodAllowed(): bool
	{
		$server = \CRestServer::instance();
		$method = mb_strtolower($server->getMethod());

		if ($method === 'batch')
		{
			return self::isBatchAllowed($server);
		}

		if (self::isControllerMethod($method))
		{
			return true;
		}

		return in_array($method, self::getAllowedMethodsLower(), true);
	}

	private static function isControllerMethod(string $method): bool
	{
		return str_starts_with($method, 'im.v2.');
	}

	private static function isBatchAllowed(\CRestServer $server): bool
	{
		$query = $server->getQuery();
		if (empty($query['cmd']) || !is_array($query['cmd']))
		{
			return false;
		}

		$allowedMethods = self::getAllowedMethodsLower();

		foreach ($query['cmd'] as $command)
		{
			if (!is_string($command))
			{
				return false;
			}

			$questionPos = mb_strrpos($command, '?');
			$method = $questionPos !== false
				? mb_substr($command, 0, $questionPos)
				: $command
			;

			$methodLower = mb_strtolower($method);
			if (!self::isControllerMethod($methodLower) && !in_array($methodLower, $allowedMethods, true))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @return list<string>
	 */
	private static function getAllowedMethodsLower(): array
	{
		self::$allowedMethodsLowerCache ??= array_map('mb_strtolower', static::ALLOWED_METHODS);

		return self::$allowedMethodsLowerCache;
	}

	private static function buildSuccessResult(int $userId): array
	{
		return [
			'user_id' => $userId,
			'scope' => implode(',', \CRestUtil::getScopeList()),
			'parameters_clear' => [],
			'auth_type' => UserGuest::AUTH_ID,
		];
	}

	private static function buildErrorResult(string $code, string $description): array
	{
		return [
			'error' => $code,
			'error_description' => $description,
			'additional' => [],
		];
	}
}
