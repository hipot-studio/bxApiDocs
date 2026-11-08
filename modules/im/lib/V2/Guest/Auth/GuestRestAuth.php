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

		// Call log (guest scenario): read-only history plus clearing the missed-calls badge.
		// Entry deletion (delete), per-entry seen marks and getChat stay excluded: a guest reads
		// the log but never edits it. getChat is the most sensitive of them: it resolves a private
		// chat by an arbitrary userId.
		'call.calllog.list',
		'call.calllog.markallasseen',
	];

	/**
	 * Allowed alone, but never inside a batch: these end the guest's own session.
	 *
	 * Batch is authorized once for the whole payload — {@see \CRestServerBatchItem::checkAuth()}
	 * re-checks only the scope, so no sub-command returns here. The session-ending call deliberately
	 * keeps the CUser of the CURRENT request authorized (see
	 * {@see AuthorizationService::invalidateCurrentGuestSession()}), so every command queued after it
	 * would still run as the guest whose session has just died. V2 methods need no such guard: each
	 * im.v2.* sub-command passes {@see \Bitrix\Im\V2\Controller\Filter\AuthorizationPrefilter}.
	 *
	 * Lower case, matched against the already lowered batch command.
	 */
	protected const BATCH_FORBIDDEN_METHODS = [
		'im.chat.leave',
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

		$token = Token::createFromRequest();

		if ($token === null && !$isSessionGuest)
		{
			return null;
		}

		// A visitor handed over to the public scope of the portal keeps the token cookie on purpose, so the
		// return through the invite link finds the same guest. Their calls belong to the page that took
		// them over: step aside and let that scope authorize them, instead of judging the call by the guest
		// whitelist. Only for a visitor without a guest session of ours - stepping aside for one would hand
		// the call to SessionAuth, which knows no whitelist at all.
		if (!$isSessionGuest && $token !== null && self::isHandedToPortalPublicScope($token))
		{
			return null;
		}

		if (!Features::isChatWithGuestsAvailable(GuestService::getInstance()->getCurrentInviterId()))
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

	/** The token lookup is memoized per request, so asking here costs the later authentication nothing. */
	private static function isHandedToPortalPublicScope(Token $token): bool
	{
		$userId = AuthenticationService::getInstance()->findUserByToken($token);

		return $userId !== null && AuthorizationService::getInstance()->isGuestScopeSuspended($userId);
	}

	private static function isMethodAllowed(): bool
	{
		$server = \CRestServer::instance();
		$method = mb_strtolower($server->getMethod());

		if ($method === 'batch')
		{
			// Same key normalization as CRestProvider::methodsBatch(): with both "cmd" and "CMD"
			// in the query the executor keeps the last one, so reading a literal "cmd" would
			// check commands that never run.
			$query = array_change_key_case($server->getQuery() ?? [], CASE_UPPER);

			return self::isEveryBatchCommandAllowed($query['CMD'] ?? null);
		}

		return self::isMethodAllowedForGuest($method);
	}

	/**
	 * Tells whether an old REST method is allowed for a guest user.
	 *
	 * @param string $method Method name in any case, e.g. "call.CallLog.list".
	 */
	private static function isMethodAllowedForGuest(string $method): bool
	{
		$methodLower = mb_strtolower($method);

		if (self::isControllerMethod($methodLower))
		{
			return true;
		}

		return in_array($methodLower, self::getAllowedMethodsLower(), true);
	}

	/**
	 * Tells whether every command of a batch request is allowed for a guest user.
	 * A single disallowed command rejects the whole batch.
	 *
	 * @param mixed $commands Raw "cmd" value of the batch request.
	 */
	private static function isEveryBatchCommandAllowed(mixed $commands): bool
	{
		if (!is_array($commands) || $commands === [])
		{
			return false;
		}

		foreach ($commands as $command)
		{
			if (!is_string($command))
			{
				return false;
			}

			// parse_url() plus 'path' is exactly how CRestProvider::methodsBatch() reads the name.
			// Do not switch to Bitrix\Main\Web\Uri here, even though the core best practice asks
			// for it by default: another parser would drift from the executor and reopen the bypass.
			$parts = parse_url($command);
			if (!is_array($parts) || !isset($parts['path']))
			{
				return false;
			}

			$methodLower = mb_strtolower($parts['path']);

			// Kept from master: im.chat.leave ends the guest session, and CRestServerBatchItem::checkAuth()
			// re-checks only the scope, so every command queued after it would still run as the guest whose
			// session has just died. Applied to the path parse_url() gave, not to the raw command.
			if (in_array($methodLower, static::BATCH_FORBIDDEN_METHODS, true))
			{
				return false;
			}

			if (!self::isMethodAllowedForGuest($methodLower))
			{
				return false;
			}
		}

		return true;
	}

	private static function isControllerMethod(string $method): bool
	{
		return str_starts_with(mb_strtolower($method), 'im.v2.');
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
