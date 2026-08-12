<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Auth;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Guest\GuestService;
use Bitrix\Im\V2\Service\Locator;

/**
 * Old REST API auth handler (rest:onRestCheckAuth). Must run before SessionAuth so guests
 * are not rejected as external users. V2 controllers (im.v2.*) are handled by
 * {@see \Bitrix\Im\V2\Controller\Filter\AuthorizationPrefilter}.
 */
class GuestRestAuth
{
	/** @var list<string>|null */
	private static ?array $allowedMethodsLowerCache = null;

	/** Old REST methods allowed for guests. V2 methods (im.v2.*) bypass this list. */
	protected const ALLOWED_METHODS = [
		'server.time',
		'smile.get',

		// Pull — required for realtime updates
		'pull.config.get',
		'pull.watch.extend',

		// Chat
		'im.chat.get',
		'im.chat.user.list',
		'im.chat.mute',
		'im.chat.file.get',
		'im.chat.leave',

		// Messages
		'im.message.add',
		'im.message.update',
		'im.message.delete',
		'im.message.like',

		// Dialog
		'im.dialog.messages.get',
		'im.dialog.messages.search',
		'im.dialog.read',
		'im.dialog.writing',
		'im.dialog.users.list',

		// Recent
		'im.recent.get',
		'im.recent.list',
		'im.recent.unread',

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
		'im.chat.favorite.add',
		'im.chat.favorite.counter.get',
		'im.chat.url.get',
		'im.chat.url.counter.get',
		'im.chat.file.collection.get',
		'im.chat.task.get',
		'im.chat.calendar.get',

		// Call (guest scenario)
		'call.call.getcalltoken',
		'call.call.tryjoincall',
		'call.call.answer',
		'call.call.decline',
		'call.call.userstatus',
		'call.call.finishcall',
		'call.call.onsharescreen',
		'call.call.onstartrecord',

		// Call runtime (guest scenario): join/answer/finish paths for the call:calls runtime.
		// getUserState is the pre-connect guard for the push cold-start branch. Call-start
		// methods (create/createChildCall/invite) are deliberately excluded — a guest never
		// initiates a call.
		'call.callmanager.getuserstate',
		'call.callmanager.getusers',
		'call.callmanager.finish',
	];

	/**
	 * Activates when a guest token is in the request (header/cookie) or the user is
	 * already authorized as im_guest (intercepts before SessionAuth rejects).
	 *
	 * @return bool|null null = not handled, true = success, false = error
	 */
	public static function onRestCheckAuth(array $query, $scope, &$res): ?bool
	{
		$globalUser = Locator::getContext()->getCUser();
		if ($globalUser === null)
		{
			return null;
		}

		$isSessionGuest = $globalUser->IsAuthorized()
			&& $globalUser->GetParam('EXTERNAL_AUTH_ID') === UserGuest::AUTH_ID
		;

		if ($isSessionGuest && !GuestService::getInstance()->isCurrentGuestSessionValid())
		{
			AuthorizationService::getInstance()->invalidateCurrentGuestSession();

			$res = self::buildErrorResult(
				AuthError::GUEST_SESSION_TERMINATED,
				'IM Guest: session terminated'
			);

			return false;
		}

		if (!Features::isChatWithGuestsAvailable(GuestService::getInstance()->getCurrentInviterId()))
		{
			return null;
		}

		$token = Token::createFromRequest();

		if ($token === null && !$isSessionGuest)
		{
			return null;
		}

		if (!self::isMethodAllowed())
		{
			$res = self::buildErrorResult(
				AuthError::GUEST_METHOD_NOT_ALLOWED,
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

		$authResult = self::authenticateByToken($token, $res);
		if ($authResult !== true)
		{
			return $authResult;
		}

		// Symmetric with the session-guest branch above: after token-auth set up the user,
		// run the full session check (link, chat membership, inviter role) so a guest with
		// a revoked link cannot squeeze a single V1-REST call through on token alone.
		if (!GuestService::getInstance()->isCurrentGuestSessionValid())
		{
			AuthorizationService::getInstance()->invalidateCurrentGuestSession();

			$res = self::buildErrorResult(
				AuthError::GUEST_SESSION_TERMINATED,
				'IM Guest: session terminated'
			);

			return false;
		}

		return true;
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
