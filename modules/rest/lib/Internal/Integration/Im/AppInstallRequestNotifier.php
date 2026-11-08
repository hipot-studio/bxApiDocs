<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Integration\Im;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\Internal\Entity\Application\AppInstallRequest\AppInstallRequest;

Loc::loadMessages(__FILE__);

final class AppInstallRequestNotifier
{
	private const NOTIFY_MODULE = 'rest';
	private const NOTIFY_EVENT = 'market_install_request';

	private readonly bool $isImModuleIncluded;

	public function __construct()
	{
		$this->isImModuleIncluded = Loader::includeModule('im');
	}

	private function buildTag(int $requestId, int $userId): string
	{
		return 'REST|MARKET_INSTALL_REQUEST|' . $requestId . '|TO|' . $userId;
	}

	/**
	 * @return array{name: string, iconUrl: string, installUrl: string, openUrl: string}
	 */
	private function resolveAppInfo(AppInstallRequest $request): array
	{
		$appCode = $request->getAppCode();
		$appVersion = $request->getAppVersion();
		$checkHash = $request->getCheckHash();
		$installHash = $request->getInstallHash();

		$encodedCode = urlencode($appCode);
		if (!empty($checkHash) && !empty($installHash))
		{
			$installUrl = '/market/detail/' . $encodedCode . '/?'
				. (!empty($appVersion) ? 'ver=' . urlencode($appVersion) . '&' : '')
				. 'install=Y'
				. '&check_hash=' . urlencode($checkHash)
				. '&install_hash=' . urlencode($installHash);
		}
		else
		{
			$installUrl = '/market/detail/' . $encodedCode . '/';
		}

		$appName = (string)($request->getAppName() ?? '');
		$appIconUrl = (string)($request->getAppIconUrl() ?? '');

		$openUrl = $installUrl;
		$appInfo = AppTable::getByClientId($appCode);
		if ($appInfo !== null)
		{
			$page = (string)\CRestUtil::getApplicationPage($appInfo['CODE'] ?? $appCode, 'CODE', $appInfo);
			if ($page !== '')
			{
				$openUrl = $page;
			}
		}

		return [
			'name'       => $appName,
			'iconUrl'    => $appIconUrl,
			'installUrl' => $installUrl,
			'openUrl'    => $openUrl,
		];
	}

	/**
	 * @param array{name: string, iconUrl: string, installUrl: string, openUrl: string} $info
	 */
	private function buildComponentParams(
		string $role,
		string $state,
		int $requestId,
		array $info,
		string $subject,
		?string $footerText,
	): array
	{
		return [
			'ROLE'         => $role,
			'STATE'        => $state,
			'REQUEST_ID'   => $requestId,
			'APP_NAME'     => $info['name'],
			'APP_ICON_URL' => $info['iconUrl'],
			'INSTALL_URL'  => $info['installUrl'],
			'OPEN_URL'     => $info['openUrl'],
			'SUBJECT'      => $subject,
			'FOOTER_TEXT'  => $footerText,
			'ENTITY' => 'MarketAppInstallRequest',
		];
	}

	public function notifyCreated(AppInstallRequest $request): void
	{
		if (!$this->isImModuleIncluded)
		{
			return;
		}

		$requestId = (int)$request->getId();
		$userId = $request->getUserId();
		$info = $this->resolveAppInfo($request);

		$userSubject = Loc::getMessage('REST_APP_INSTALL_REQUEST_USER_SUBJECT');
		\CIMNotify::Add([
			'TO_USER_ID'    => $userId,
			'FROM_USER_ID'  => 0,
			'NOTIFY_TYPE'   => IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => self::NOTIFY_MODULE,
			'NOTIFY_EVENT'  => self::NOTIFY_EVENT,
			'NOTIFY_TAG'    => $this->buildTag($requestId, $userId),
			'NOTIFY_MESSAGE' => $userSubject,
			'PARAMS' => [
				'COMPONENT_ID'     => 'MarketAppInstallRequestEntity',
				'COMPONENT_PARAMS' => $this->buildComponentParams(
					'user',
					'pending',
					$requestId,
					$info,
					$userSubject,
					null,
				),
			],
		]);

		$adminSubject = Loc::getMessage('REST_APP_INSTALL_REQUEST_ADMIN_SUBJECT', [
			'#AUTHOR#' => '#AUTHOR#',
		]);
		$adminIds = \CRestUtil::getAdministratorIdList();
		$adminMessage = Loc::getMessage('REST_APP_INSTALL_REQUEST_ADMIN_MESSAGE');
		foreach ($adminIds as $adminId)
		{
			\CIMNotify::Add([
				'TO_USER_ID'    => $adminId,
				'FROM_USER_ID'  => $userId,
				'NOTIFY_TYPE'   => IM_NOTIFY_FROM,
				'NOTIFY_MODULE' => self::NOTIFY_MODULE,
				'NOTIFY_EVENT'  => self::NOTIFY_EVENT,
				'NOTIFY_TAG'    => $this->buildTag($requestId, (int)$adminId),
				'NOTIFY_MESSAGE' => $adminMessage,
				'PARAMS' => [
					'COMPONENT_ID'     => 'MarketAppInstallRequestEntity',
					'COMPONENT_PARAMS' => $this->buildComponentParams(
						'admin',
						'pending',
						$requestId,
						$info,
						$adminSubject,
						null,
					),
				],
			]);
		}
	}

	/**
	 * @param AppInstallRequest[] $requests
	 */
	public function notifyGroupRejected(array $requests, int $resolvedAdminId): void
	{
		if (!$this->isImModuleIncluded)
		{
			return;
		}

		if (empty($requests))
		{
			return;
		}

		$adminIds = \CRestUtil::getAdministratorIdList();

		$firstRequest = $requests[0];
		$info = $this->resolveAppInfo($firstRequest);

		$userSubject = Loc::getMessage('REST_APP_INSTALL_REQUEST_USER_SUBJECT');
		$userFooter = Loc::getMessage('REST_APP_INSTALL_REQUEST_REJECTED_USER_MESSAGE');
		$adminSubject = Loc::getMessage('REST_APP_INSTALL_REQUEST_ADMIN_SUBJECT', [
			'#AUTHOR#' => '#AUTHOR#',
		]);
		$adminFooter = Loc::getMessage('REST_APP_INSTALL_REQUEST_REJECTED_ADMIN_MESSAGE');
		$adminMessage = Loc::getMessage('REST_APP_INSTALL_REQUEST_ADMIN_MESSAGE');
		foreach ($requests as $request)
		{
			$requestId = (int)$request->getId();
			$userId = $request->getUserId();

			$userTag = $this->buildTag($requestId, $userId);
			\CIMNotify::DeleteByTag($userTag);
			\CIMNotify::Add([
				'TO_USER_ID'    => $userId,
				'FROM_USER_ID'  => 0,
				'NOTIFY_TYPE'   => IM_NOTIFY_SYSTEM,
				'NOTIFY_MODULE' => self::NOTIFY_MODULE,
				'NOTIFY_EVENT'  => self::NOTIFY_EVENT,
				'NOTIFY_TAG'    => $userTag,
				'NOTIFY_MESSAGE' => $userSubject,
				'PARAMS' => [
					'COMPONENT_ID'     => 'MarketAppInstallRequestEntity',
					'COMPONENT_PARAMS' => $this->buildComponentParams(
						'user',
						'rejected',
						$requestId,
						$info,
						$userSubject,
						$userFooter,
					),
				],
			]);

			foreach ($adminIds as $adminId)
			{
				\CIMNotify::DeleteByTag($this->buildTag($requestId, (int)$adminId));
			}

			$resolvedAdminTag = $this->buildTag($requestId, $resolvedAdminId);
			\CIMNotify::Add([
				'TO_USER_ID'    => $resolvedAdminId,
				'FROM_USER_ID'  => $userId,
				'NOTIFY_TYPE'   => IM_NOTIFY_FROM,
				'NOTIFY_MODULE' => self::NOTIFY_MODULE,
				'NOTIFY_EVENT'  => self::NOTIFY_EVENT,
				'NOTIFY_TAG'    => $resolvedAdminTag,
				'NOTIFY_MESSAGE' => $adminMessage,
				'PARAMS' => [
					'COMPONENT_ID'     => 'MarketAppInstallRequestEntity',
					'COMPONENT_PARAMS' => $this->buildComponentParams(
						'admin',
						'rejected',
						$requestId,
						$info,
						$adminSubject,
						$adminFooter,
					),
				],
			]);
		}
	}

	/**
	 * @param AppInstallRequest[] $requests
	 */
	public function notifyGroupInstalled(array $requests): void
	{
		if (!$this->isImModuleIncluded)
		{
			return;
		}

		if (empty($requests))
		{
			return;
		}

		$adminIds = \CRestUtil::getAdministratorIdList();

		$firstRequest = $requests[0];
		$info = $this->resolveAppInfo($firstRequest);

		$userSubject = Loc::getMessage('REST_APP_INSTALL_REQUEST_USER_SUBJECT');
		$userFooter = Loc::getMessage('REST_APP_INSTALL_REQUEST_INSTALLED_USER_MESSAGE');
		$adminSubject = Loc::getMessage('REST_APP_INSTALL_REQUEST_ADMIN_SUBJECT', [
			'#AUTHOR#' => '#AUTHOR#',
		]);
		$adminFooter = Loc::getMessage('REST_APP_INSTALL_REQUEST_INSTALLED_ADMIN_MESSAGE');
		$adminMessage = Loc::getMessage('REST_APP_INSTALL_REQUEST_ADMIN_MESSAGE');

		foreach ($requests as $request)
		{
			$requestId = (int)$request->getId();
			$userId = $request->getUserId();
			$resolvedById = (int)$request->getResolvedById();

			$userTag = $this->buildTag($requestId, $userId);
			\CIMNotify::DeleteByTag($userTag);
			\CIMNotify::Add([
				'TO_USER_ID'    => $userId,
				'FROM_USER_ID'  => 0,
				'NOTIFY_TYPE'   => IM_NOTIFY_SYSTEM,
				'NOTIFY_MODULE' => self::NOTIFY_MODULE,
				'NOTIFY_EVENT'  => self::NOTIFY_EVENT,
				'NOTIFY_TAG'    => $userTag,
				'NOTIFY_MESSAGE' => $userSubject,
				'PARAMS' => [
					'COMPONENT_ID'     => 'MarketAppInstallRequestEntity',
					'COMPONENT_PARAMS' => $this->buildComponentParams(
						'user',
						'installed',
						$requestId,
						$info,
						$userSubject,
						$userFooter,
					),
				],
			]);

			foreach ($adminIds as $adminId)
			{
				$adminTag = $this->buildTag($requestId, (int)$adminId);
				\CIMNotify::DeleteByTag($adminTag);

				if ((int)$adminId === $resolvedById)
				{
					\CIMNotify::Add([
						'TO_USER_ID'    => $adminId,
						'FROM_USER_ID'  => $userId,
						'NOTIFY_TYPE'   => IM_NOTIFY_FROM,
						'NOTIFY_MODULE' => self::NOTIFY_MODULE,
						'NOTIFY_EVENT'  => self::NOTIFY_EVENT,
						'NOTIFY_TAG'    => $adminTag,
						'NOTIFY_MESSAGE' => $adminMessage,
						'PARAMS' => [
							'COMPONENT_ID'     => 'MarketAppInstallRequestEntity',
							'COMPONENT_PARAMS' => $this->buildComponentParams(
								'admin',
								'installed',
								$requestId,
								$info,
								$adminSubject,
								$adminFooter,
							),
						],
					]);
				}
			}
		}
	}

	/**
	 * @param AppInstallRequest[] $requests
	 */
	public function dismissOtherAdmins(array $requests, int $exceptAdminId): void
	{
		if (!$this->isImModuleIncluded)
		{
			return;
		}

		$adminIds = \CRestUtil::getAdministratorIdList();

		foreach ($requests as $request)
		{
			$requestId = (int)$request->getId();

			foreach ($adminIds as $adminId)
			{
				if ((int)$adminId === $exceptAdminId)
				{
					continue;
				}

				\CIMNotify::DeleteByTag($this->buildTag($requestId, (int)$adminId));
			}
		}
	}
}
