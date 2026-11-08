<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage rest
 * @copyright 2001-2016 Bitrix
 */

namespace Bitrix\Rest\APAuth;

use Bitrix\Main\Authentication\ApplicationManager;
use Bitrix\Main\Authentication\ApplicationPasswordTable;
use Bitrix\Main\Context;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Rest\Engine\Access;
use Bitrix\Rest\Engine\Access\HoldEntity;
use Bitrix\Rest\Internal\Entity\SystemUser\ResourceType;
use Bitrix\Rest\Internal\Exception\Payment\FeatureNotAvailableOnCurrentPlanExceptionInterface;
use Bitrix\Rest\Internal\Integration\Bitrix24\TariffAccessService;
use Bitrix\Rest\Internal\Repository\SystemUser\SystemUserRepository;
use Bitrix\Rest\Internal\Service;
use Throwable;

class Auth
{
	const AUTH_TYPE = 'apauth';

	protected static $authQueryParams = array(
		'UID' => 'aplogin', 'PASSWORD' => 'ap',
	);

	protected static $integrationScope = array('crm', 'telephony', 'imopenlines');

	protected static $scopeCache = array();

	protected static ?Service\IncomingWebhook\ExpirationChecker $webhookExpirationChecker = null;

	protected static ?Service\IncomingWebhook\Invalidator $webhookInvalidator = null;

	public static function onRestCheckAuth(array $query, $scope, &$res)
	{
		$auth = array();
		foreach(static::$authQueryParams as $key)
		{
			if (array_key_exists($key, $query))
			{
				$auth[$key] = $query[$key];
			}
			else
			{
				return null;
			}
		}

		if (!defined('REST_APAUTH_ALLOW_HTTP') && !Context::getCurrent()->getRequest()->isHttps())
		{
			$res = array('error' => 'INVALID_REQUEST', 'error_description' => 'Https required.');
			return false;
		}

		$password = $auth[static::$authQueryParams['PASSWORD']];
		if (!is_string($password))
		{
			$res = [
				'error' => 'INVALID_CREDENTIALS',
				'error_description' => 'Invalid request credentials',
				'parameters_clear' => static::$authQueryParams,
				'auth_type' => static::AUTH_TYPE,
			];

			return false;
		}

		// the hold list is keyed by the raw password, so the check needs no database lookup
		if (HoldEntity::is(HoldEntity::TYPE_WEBHOOK, $password))
		{
			$res = [
				'error' => 'OVERLOAD_LIMIT',
				'error_description' => 'REST API is blocked due to overload.',
				'parameters_clear' => static::$authQueryParams,
				'auth_type' => static::AUTH_TYPE,
			];

			return false;
		}

		$tokenInfo = static::check($auth, $scope);

		if (is_array($tokenInfo))
		{
			$error = array_key_exists('error', $tokenInfo);

			if (!$error)
			{
				$passwordId = (int)$tokenInfo['password_id'];
				$upsellUserId = (int)($tokenInfo['user_id'] ?? 0);

				try
				{
					Access::ensureIsAvailableAPAuthByPasswordId($passwordId);
					$accessException = null;
				}
				catch(Throwable $e)
				{
					$accessException = $e;
				}

				if (
					$accessException !== null
					|| (
						Access::needCheckCount()
						&& !Access::isAvailableCount(Access::ENTITY_TYPE_WEBHOOK, $passwordId)
					)
				)
				{
					if ($accessException instanceof FeatureNotAvailableOnCurrentPlanExceptionInterface)
					{
						$tokenInfo = [
							'error' => FeatureNotAvailableOnCurrentPlanExceptionInterface::ERROR_CODE,
							'error_description' => $accessException->getMessage(),
							'error_status' => \CRestServer::STATUS_FORBIDDEN,
							'exception' => $accessException,
						];
					}
					else
					{
						$tokenInfo = [
							'error' => 'ACCESS_DENIED',
							'error_description' => 'REST is available only by subscription.',
						];
						if ($accessException instanceof Throwable)
						{
							$tokenInfo['exception'] = $accessException;
						}
					}

					$tokenInfo = static::appendUpsellProjection(
						$tokenInfo,
						userId: $upsellUserId,
					);
					$error = true;
				}
			}

			if (!$error && $tokenInfo['user_id'] > 0)
			{
				$tokenInfo['scope'] = implode(',', static::getPasswordScope($tokenInfo['password_id']));

				global $USER;
				if ($USER instanceof \CUser && $USER->isAuthorized())
				{
					if ((int)$USER->GetID() !== (int)$tokenInfo['user_id'])
					{
						$tokenInfo = [
							'error' => 'authorization_error',
							'error_description' => Loc::getMessage('REST_AP_AUTH_ERROR_LOGOUT_BEFORE'),
						];
						$error = true;
					}
				}
				elseif (!\CRestUtil::makeAuth($tokenInfo, self::AUTH_TYPE, $tokenInfo['password_id'] ?? 0))
				{
					$tokenInfo = array('error' => 'authorization_error', 'error_description' => 'Unable to authorize user');
					$error = true;
				}
				else
				{
					PasswordTable::update($tokenInfo['password_id'], array(
						'DATE_LOGIN' => new DateTime(),
						'LAST_IP' => Context::getCurrent()->getRequest()->getRemoteAddress(),
					));

				unset($tokenInfo['application_id']);
				}
			}

			$res = $tokenInfo;

			$res['parameters_clear'] = static::$authQueryParams;
			$res['auth_type'] = static::AUTH_TYPE;

			return !$error;
		}

		return false;
	}

	private static function appendUpsellProjection(
		array $tokenInfo,
		?\Closure $projectionResolver = null,
		int $userId = 0,
	): array
	{
		$projectionResolver ??= static fn(int $resolvedUserId) => (new TariffAccessService())
			->getUpsellProjection($resolvedUserId)
		;

		try
		{
			$projection = $projectionResolver($userId);
		}
		catch (\Throwable)
		{
			return $tokenInfo;
		}

		return $projection === null ? $tokenInfo : array_replace($tokenInfo, $projection->toArray());
	}

	protected static function check($auth, $scope)
	{
		$result = [
			'error' => 'INVALID_CREDENTIALS',
			'error_description' => 'Invalid request credentials',
		];

		if (empty($auth[static::$authQueryParams['UID']]))
		{
			return $result;
		}

		$uid = $auth[static::$authQueryParams['UID']];
		if (strval(intval($uid)) === $uid)
		{
			$userInfo = ['ID' => intval($uid)];
		}
		else
		{
			$userInfo = UserTable::getList([
				'filter' => [
					'=LOGIN' => $uid,
					'=ACTIVE' => 'Y',
				],
				'select' => ['ID'],
			])->fetch();
		}

		if (!empty($userInfo))
		{
			$passwordInfo = PasswordTable::getList([
				'filter' => [
					'=USER_ID' => $userInfo['ID'],
					'=PASSWORD' => $auth[static::$authQueryParams['PASSWORD']],
					'=ACTIVE' => PasswordTable::ACTIVE,
				],
				'select' => ['ID', 'DATE_EXPIRE'],
				'cache' => ['ttl' => 86400],
			])->fetch();
			if (!$passwordInfo)
			{
				$passwordInfo = static::checkOldPassword($userInfo['ID'], $auth[static::$authQueryParams['PASSWORD']]);
			}

			if (!$passwordInfo)
			{
				/** @var SystemUserRepository $systemUserRepository */
				$systemUserRepository = ServiceLocator::getInstance()->get(SystemUserRepository::class);
				$replaceUser = $systemUserRepository->getByResourceIdAndResourceType((int)$userInfo['ID'], ResourceType::WEBHOOK);
				if ($replaceUser !== null)
				{
					$auth[static::$authQueryParams['UID']] = (string)$replaceUser->getUserId();
					return self::check($auth, $scope);
				}
			}

			if ($passwordInfo)
			{
				if (static::getWebhookExpirationChecker()->isExpired($passwordInfo))
				{
					static::getWebhookInvalidator()->invalidateByPasswordId((int)$passwordInfo['ID']);

					return $result;
				}

				if (static::checkPermission($passwordInfo['ID'], $scope) === true)
				{
					$result = [
						'user_id' => $userInfo['ID'],
						'password_id' => $passwordInfo['ID'],
					];
				}
				else
				{
					$result = [
						'error' => 'insufficient_scope',
						'error_description' => 'The request requires higher privileges than provided by the webhook token'
					];
				}
			}
		}

		return $result;
	}

	protected static function getWebhookExpirationChecker(): Service\IncomingWebhook\ExpirationChecker
	{
		return static::$webhookExpirationChecker ??= new Service\IncomingWebhook\ExpirationChecker();
	}

	protected static function getWebhookInvalidator(): Service\IncomingWebhook\Invalidator
	{
		return static::$webhookInvalidator ??= new Service\IncomingWebhook\Invalidator();
	}

	protected static function checkOldPassword($userId, $password)
	{
		$appPassword = ApplicationPasswordTable::findPassword($userId, $password);
		if($appPassword !== false)
		{
			if($appPassword["APPLICATION_ID"] === Application::ID)
			{
				$appManager = ApplicationManager::getInstance();
				if($appManager->checkScope($appPassword["APPLICATION_ID"]) === true)
				{
					return static::convertOldPassword($appPassword, $password);
				}
			}
		}

		return false;
	}

	protected static function convertOldPassword($appPassword, $password)
	{
		$dbRes = ApplicationPasswordTable::getById($appPassword['ID']);
		$oldPassword = $dbRes->fetch();
		if($oldPassword)
		{
			ApplicationPasswordTable::delete($appPassword['ID']);
			$result = PasswordTable::add(array(
				'USER_ID' => $oldPassword['USER_ID'],
				'PASSWORD' => $password,
				'ACTIVE' => PasswordTable::ACTIVE,
				'TITLE' => $oldPassword['SYSCOMMENT'],
				'COMMENT' => $oldPassword['COMMENT'],
				'DATE_CREATE' => $oldPassword['DATE_CREATE'],
				'DATE_LOGIN' => $oldPassword['DATE_LOGIN'],
				'LAST_IP' => $oldPassword['LAST_IP'],
			));
			if($result->isSuccess())
			{
				$passwordId = $result->getId();

				foreach(static::$integrationScope as $scope)
				{
					PermissionTable::add(array(
						'PASSWORD_ID' => $passwordId,
						'PERM' => $scope,
					));
				}

				return array(
					'ID' => $passwordId,
				);
			}
		}

		return false;
	}

	protected static function checkPermission($passwordId, $scope)
	{
		if($scope === \CRestUtil::GLOBAL_SCOPE)
		{
			return true;
		}

		$scopeList = static::getPasswordScope($passwordId);
		$scopeList = \Bitrix\Rest\Engine\RestManager::fillAlternativeScope($scope, $scopeList);
		return in_array($scope, $scopeList);
	}

	protected static function getPasswordScope($passwordId): array
	{
		if (!array_key_exists($passwordId, static::$scopeCache))
		{
			static::$scopeCache[$passwordId] = [];

			$dbRes = PermissionTable::query()
				->setSelect(['PERM'])
				->where('PASSWORD_ID', $passwordId)
				->setCacheTtl(86400)
				->exec();
			while ($perm = $dbRes->fetch())
			{
				static::$scopeCache[$passwordId][] = $perm['PERM'];
			}
		}

		return static::$scopeCache[$passwordId];
	}
}
