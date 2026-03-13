<?php
namespace Bitrix\Call\Rest;

use Bitrix\Call\Conference;

/**
 * @internal
 */
class Auth
{
	public const AUTH_TYPE = 'call';

	protected const AUTH_CODE_GUEST = 'guest';
	protected const PASSWORD_CHECK_METHOD = 'call.videoconf.password.check';

	protected const METHODS_WITHOUT_AUTH = [
		'im.call.user.register',/** @deprecated  */
		'im.videoconf.password.check',/** @deprecated  */
		'call.user.register',
		'call.videoconf.password.check',
	];

	protected const METHODS_WITH_AUTH = [
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
		'im.dialog.users.list',
		'im.message.add',
		'im.message.like',
		'im.chat.get',
		'im.dialog.read',
		'im.dialog.read.all',
		'im.dialog.messages.get',
		'im.dialog.messages.get.init',
		'im.user.get',
		'mobile.browser.const.get',
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
					$res = [
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
						$res = [
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
				$res = [
					'error' => 'CALL_AUTH_METHOD_ERROR',
					'error_description' => 'Call: you don\'t have access to use this method [1]',
					'additional' => []
				];

				return false;
			}
		}
		else if (!preg_match("/^[a-fA-F0-9]{32}$/i", $authCode))
		{
			$res = [
				'error' => 'CALL_AUTH_FAILED',
				'error_description' => 'Call: user auth failed [code is not correct]',
				'additional' => []
			];
		}

		if (!self::checkQueryMethod(array_merge(self::METHODS_WITH_AUTH, self::METHODS_WITHOUT_AUTH)))
		{
			$res = [
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
					$res = [
						'error' => 'CALL_AUTH_DIFF_USER',
						'error_description' => 'Call: you are authorized with a different user [2]',
						'additional' => ['hash' => mb_substr($USER->GetParam('XML_ID'), mb_strlen(self::AUTH_TYPE) + 1)]
					];

					return false;
				}
			}
			else
			{
				$res = [
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

		$res = [
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
		if (\CRestServer::instance()->getMethod() == 'batch')
		{
			$result = false;
			foreach (\CRestServer::instance()->getQuery()['cmd'] as $key => $method)
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
			$result = in_array(\CRestServer::instance()->getMethod(), $whiteListMethods);
		}

		return $result;
	}
}