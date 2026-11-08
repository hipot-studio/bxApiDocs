<?php
namespace Bitrix\Call\Rest;

use Bitrix\Call\Conference;

/**
 * @internal
 */
class Auth
{
	public const AUTH_TYPE = 'call';

	/**
	 * Single source of truth for the uniform guest pre-registration rejection body. Every
	 * pre-registration denial on the guest register path (missing / inactive conference, wrong
	 * password, alias not matching the conference) returns this error + description so the response
	 * body cannot be used to tell an open, password-protected or non-existent conference apart by
	 * videoconf_id. HTTP status is intentionally left as-is.
	 */
	public const ACCESS_DENIED_ERROR_CODE = 'CALL_AUTH_ACCESS_DENIED';
	public const ACCESS_DENIED_ERROR_DESCRIPTION = 'Call: access to conference is denied';

	protected const REGISTER_METHODS = [
		'call.user.register',
		'im.call.user.register',
	];

	protected const AUTH_CODE_GUEST = 'guest';
	protected const PASSWORD_CHECK_METHOD = 'call.videoconf.password.check';

	protected const METHODS_WITHOUT_AUTH = [
		'im.call.user.register',/** @deprecated  */
		'im.videoconf.password.check',/** @deprecated  */
		'call.user.register',
		'call.videoconf.password.check',
	];

	protected const METHODS_WITH_AUTH = [
		'call.Call.getCallToken',
		'call.CallManager.create',
		'call.CallManager.invite',
		'call.CallManager.answer',
		'call.CallManager.ping',
		'im.call.channel.public.list',/** @deprecated  */
		'call.channel.public.list',
		'call.CallManager.hangup',
		'call.CallManager.decline',
		'call.CallManager.getusers',
		'call.CallManager.get',
		'call.CallManager.tryJoinCall',
		'call.Disk.upload',
		'call.Disk.commit',
		'local.call.log',
		// user
		'im.call.user.update',/** @deprecated  */
		'call.user.update',
		//voximplant
		'voximplant.authorization.get',
		'voximplant.authorization.onerror',
		'voximplant.authorization.signonetimekey',
		//call
		'call.call.tryjoincall',
		'call.call.getcalltoken',
		// im
		'pull.config.get',
		'im.message.add',
		'im.message.like',
		'im.chat.get',
		'im.dialog.read',
		'im.dialog.read.all',
		'im.dialog.writing',
		'im.dialog.users.list',
		'im.dialog.messages.get',
		'im.dialog.messages.get.init',
		'im.user.get',
		'mobile.browser.const.get',
		'im.disk.folder.get',
		'im.disk.file.commit',
		'smile.get',
	];

	// TODO sync AUTH_ID_PARAM with file /rest/services/rest/index.php
	protected const AUTH_ID_PARAM = 'call_auth_id';

	protected static $authQueryParams = [
		self::AUTH_ID_PARAM,
	];

	public static function onRestCheckAuth(array $query, $scope, &$res)
	{
		global $USER;

		$authCode = null;
		foreach(static::$authQueryParams as $key)
		{
			if(array_key_exists($key, $query))
			{
				$authCode = $query[$key];
				break;
			}
		}

		if ($authCode === null)
		{
			return null;
		}

		$conference = null;
		$method = \CRestServer::instance()->getMethod();

		// Body unification (variant c) is scoped to the guest register methods only: other call-auth
		// methods sharing these branches must keep their original error codes.
		$isGuestRegisterMethod = in_array(mb_strtolower((string)$method), self::REGISTER_METHODS, true);

		// A register method must be a standalone top-level call. A REST batch authenticates only its
		// outer envelope, so inner register commands receive neither an independent password check nor
		// the register-specific error unification below. Reject the whole batch uniformly, independent of
		// any videoconf_id, before the outer gate can leak a distinguishing code (Mantis 251236 oracle).
		if (mb_strtolower((string)$method) === 'batch' && self::batchContainsRegisterMethod())
		{
			$res = self::getAccessDeniedResult();

			return false;
		}

		if (
			$method === self::PASSWORD_CHECK_METHOD
			|| $method === 'im.videoconf.password.check'//todo: remove deprecated method
		)
		{
			$conference = Conference::getById((int)$query['videoconf_id']);

			if (!$conference || !$conference->isActive())
			{
				$res = [
					'error' => 'CALL_AUTH_NOT_ACTIVE',
					'error_description' => 'Call: conference is not active',
					'additional' => []
				];

				return false;
			}
		}
		else
		{
			$storage = \Bitrix\Main\Application::getInstance()->getLocalSession('conference_check_' . $query['videoconf_id']);
			if($storage->get('checked') === true)
			{
				//TODO: check conf status by checking start date from cache
			}
			else
			{
				$conference = Conference::getById((int)$query['videoconf_id']);

				if (!$conference || !$conference->isActive())
				{
					$res = $isGuestRegisterMethod
						? self::getAccessDeniedResult()
						: [
							'error' => 'CALL_AUTH_VIDEOCONF_NOT_ACTIVE',
							'error_description' => 'Call: conference is not active',
							'additional' => []
						];

					return false;
				}

				if ($conference->isPasswordRequired())
				{
					if ($conference->getPassword() === $query['videoconf_password'])
					{
						$storage->set('checked', true);
					}
					else
					{
						$res = $isGuestRegisterMethod
							? self::getAccessDeniedResult()
							: [
								'error' => 'CALL_AUTH_ACCESS_DENIED',
								'error_description' => 'Call: access to conference is denied',
								'additional' => []
							];

						return false;
					}
				}
			}
		}

		if ($authCode == self::AUTH_CODE_GUEST)
		{
			if (self::checkQueryMethod(self::METHODS_WITHOUT_AUTH))
			{
				$res = self::getSuccessfulResult();

				return true;
			}
			else
			{
				$res = $isGuestRegisterMethod
					? self::getAccessDeniedResult()
					: [
						'error' => 'CALL_AUTH_METHOD_ERROR',
						'error_description' => 'Call: you don\'t have access to use this method [1]',
						'additional' => []
					];

				return false;
			}
		}
		else if (!preg_match("/^[a-fA-F0-9]{32}$/i", $authCode))
		{
			$res = $isGuestRegisterMethod
				? self::getAccessDeniedResult()
				: [
					'error' => 'CALL_AUTH_FAILED',
					'error_description' => 'Call: user auth failed [code is not correct]',
					'additional' => []
				];
		}

		if (!self::checkQueryMethod(array_merge(self::METHODS_WITH_AUTH, self::METHODS_WITHOUT_AUTH)))
		{
			$res = $isGuestRegisterMethod
				? self::getAccessDeniedResult()
				: [
					'error' => 'CALL_AUTH_METHOD_ERROR',
					'error_description' => 'Call: you don\'t have access to use this method [2]',
					'additional' => []
				];

			return false;
		}

		$xmlId = self::AUTH_TYPE."|".$authCode;

		if ($USER->IsAuthorized())
		{
			if ($USER->GetParam('EXTERNAL_AUTH_ID') == 'call')
			{
				if ($USER->GetParam('XML_ID') == $xmlId)
				{
					$res = self::getSuccessfulResult();

					\CUser::SetLastActivityDate($USER->GetID(), true);

					return true;
				}
				else
				{
					$res = $isGuestRegisterMethod
						? self::getAccessDeniedResult()
						: [
							'error' => 'CALL_AUTH_DIFF_USER',
							'error_description' => 'Call: you are authorized with a different user [2]',
							'additional' => ['hash' => mb_substr($USER->GetParam('XML_ID'), mb_strlen(self::AUTH_TYPE) + 1)]
						];

					return false;
				}
			}
			else
			{
				$res = $isGuestRegisterMethod
					? self::getAccessDeniedResult()
					: [
						'error' => 'CALL_AUTH_PORTAL_USER',
						'error_description' => 'Call: you are authorized with a portal user [2]',
						'additional' => []
					];

				return false;
			}
		}

		$userData = \Bitrix\Main\UserTable::getList(
			[
				'select' => ['ID', 'EXTERNAL_AUTH_ID'],
				'filter' => ['=XML_ID' => $xmlId]
			]
		)->fetch();

		if ($userData && $userData['EXTERNAL_AUTH_ID'] == 'call')
		{
			self::authorizeById($userData['ID']);

			$res = self::getSuccessfulResult();

			\CUser::SetLastActivityDate($USER->GetID(), true);

			return true;
		}

		$res = $isGuestRegisterMethod
			? self::getAccessDeniedResult()
			: [
				'error' => 'CALL_AUTH_FAILED',
				'error_description' => 'Call: user auth failed [user not found]',
				'additional' => []
			];

		return false;
	}

	public static function authorizeById($userId, $setCookie = null, $skipAuthorizeCheck = false)
	{
		global $USER;

		if (!$skipAuthorizeCheck && $USER->IsAuthorized())
		{
			return false;
		}

		$context = \Bitrix\Main\Context::getCurrent();

		if (is_null($setCookie))
		{
			$setCookie = false;
			if ($context->getRequest()->getCookieRaw('BITRIX_CALL_AUTH'))
			{
				$setCookie = true;
			}
		}

		if ($USER->GetID() != $userId)
		{
			$USER->Authorize($userId, $setCookie, $setCookie, 'public');
		}

		$cookie = new \Bitrix\Main\Web\Cookie('BITRIX_CALL_AUTH', 'Y', null, false);
		$cookie->setHttpOnly(false);
		$context->getResponse()->addCookie($cookie);

		$authCode = str_replace(self::AUTH_TYPE.'|', '', $USER->GetParam('XML_ID'));

		$cookie = new \Bitrix\Main\Web\Cookie('BITRIX_CALL_HASH', $authCode, null, false);
		$cookie->setHttpOnly(false);
		$context->getResponse()->addCookie($cookie);

		return true;
	}

	/**
	 * Uniform rejection body for guest pre-registration failures.
	 */
	private static function getAccessDeniedResult(): array
	{
		return [
			'error' => self::ACCESS_DENIED_ERROR_CODE,
			'error_description' => self::ACCESS_DENIED_ERROR_DESCRIPTION,
			'additional' => [],
		];
	}

	/**
	 * True when the current REST batch envelope carries a guest register command. Command parsing
	 * mirrors the real dispatcher {@see \CRestProvider::methodsBatch()} exactly: the envelope keys are
	 * upper-cased and the CMD list is read, then each command's method is taken from its parse_url()
	 * path. Parsing it any other way (splitting on the last `?`, or reading a lower-case cmd) would let
	 * a register command slip past this check while the dispatcher still runs it, which re-opens the
	 * enumeration oracle (Mantis 251236).
	 */
	private static function batchContainsRegisterMethod(): bool
	{
		$query = array_change_key_case(\CRestServer::instance()->getQuery(), CASE_UPPER);
		$commands = $query['CMD'] ?? null;
		if (!is_array($commands))
		{
			return false;
		}

		// The dispatcher only executes the first BATCH_MAX_LENGTH commands (its counter advances on every
		// element, string or not), so scanning past that would parse commands that never run.
		foreach (array_slice($commands, 0, \CRestUtil::BATCH_MAX_LENGTH) as $command)
		{
			if (!is_string($command))
			{
				continue;
			}

			$commandMethod = parse_url($command, PHP_URL_PATH);
			if (
				is_string($commandMethod)
				&& in_array(mb_strtolower($commandMethod), self::REGISTER_METHODS, true)
			)
			{
				return true;
			}
		}

		return false;
	}

	private static function getSuccessfulResult()
	{
		global $USER;

		return [
			'user_id' => $USER->GetID(),
			'scope' => implode(',', \CRestUtil::getScopeList()),
			'parameters_clear' => static::$authQueryParams,
			'auth_type' => static::AUTH_TYPE,
		];
	}

	private static function checkQueryMethod($whiteListMethods)
	{
		$whiteListMethods = array_map('mb_strtolower', $whiteListMethods);
		if (\CRestServer::instance()->getMethod() == 'batch')
		{
			$result = false;
			foreach (\CRestServer::instance()->getQuery()['cmd'] as $method)
			{
				$method = mb_substr($method, 0, mb_strrpos($method, '?'));
				$result = in_array(mb_strtolower($method), $whiteListMethods);
				if (!$result)
				{
					break;
				}
			}
		}
		else
		{
			$method = mb_strtolower(\CRestServer::instance()->getMethod());
			$result = in_array($method, $whiteListMethods);
		}

		return $result;
	}
}
