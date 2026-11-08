<?php

namespace Bitrix\Rest\Marketplace;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Uri;
use Bitrix\Main;
use Bitrix\Rest\Internal\Access\AppAccessChecker;
use Bitrix\Rest\Internal\Integration\Market\ApplicationLimitProvider;
use Bitrix\Rest\Internal\Service\Application\ApplicationInstallationFinalizer;
use Bitrix\Rest\PlacementTable;
use Bitrix\Rest\Engine\Access;
use Bitrix\Rest\AppLangTable;
use Bitrix\Rest\OAuthService;
use Bitrix\Rest\AppLogTable;
use Bitrix\Rest\EventTable;
use Bitrix\Rest\Analytic;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\Internal\Entity\Application\AppInstallCompletionMode;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;
use Bitrix\Rest\Public\Command\Application\Access\SetAppAccessCommand;
use Bitrix\Rest\Public\Provider\ApplicationProvider;
use CRestUtil;

Loc::loadMessages(__FILE__);

class Application
{
	/**
	 * Id of user on whose behalf are the actions do. If not set - use current system user
	 * @var null
	 */
	protected static ?int $contextUserId = null;

	public static function setContextUserId(int $id): void
	{
		self::$contextUserId = $id;
	}

	public static function getContextUserId(): ?int
	{
		return self::$contextUserId;
	}

	private static function resolveInstallCompletionMode(array $appDetailInfo): AppInstallCompletionMode
	{
		if ($appDetailInfo['OPEN_API'] === 'Y' || empty($appDetailInfo['INSTALL_URL']))
		{
			return AppInstallCompletionMode::Immediate;
		}

		return AppInstallCompletionMode::Deferred;
	}

	private static function hasMenuTitle(array $appDetailInfo): bool
	{
		if (!isset($appDetailInfo['MENU_TITLE']) || !is_array($appDetailInfo['MENU_TITLE']))
		{
			return false;
		}

		foreach ($appDetailInfo['MENU_TITLE'] as $langName)
		{
			if (trim((string)$langName) !== '')
			{
				return true;
			}
		}

		return false;
	}

	private static function describeInstallationFailure(
		Main\Result $failure,
		array $appDetailInfo,
		array $fallback
	): array
	{
		$errors = $failure->getErrorCollection();
		$limitExceeded = $errors->getErrorByCode(
			ApplicationInstallationFinalizer::ERROR_MARKET_APPLICATION_LIMIT_EXCEEDED,
		);
		if ($limitExceeded !== null)
		{
			$limitReached = [
				'error' => ApplicationInstallationFinalizer::ERROR_MARKET_APPLICATION_LIMIT_EXCEEDED,
				'errorDescription' => Loc::getMessage('RMP_ERROR_ACCESS_DENIED'),
				'helperCode' => Access::getHelperCode(
					Access::ACTION_INSTALL,
					Access::ENTITY_TYPE_APP,
					$appDetailInfo,
				),
			];
			$applicationLimit = (new ApplicationLimitProvider())->getProjection();
			if ($applicationLimit !== null)
			{
				$limitReached['vibePlusApplicationLimit'] = $applicationLimit;
			}

			return $limitReached;
		}

		$lockFailed = $errors->getErrorByCode(ApplicationInstallationFinalizer::ERROR_LOCK_NOT_ACQUIRED) !== null;
		$updateFailed = $errors->getErrorByCode(ApplicationInstallationFinalizer::ERROR_UPDATE_FAILED) !== null;

		$fallback['errorDescription'] = $lockFailed || $updateFailed
			? Loc::getMessage('RMP_INSTALL_ERROR')
			: implode('<br />', $failure->getErrorMessages());

		return $fallback;
	}

	public static function install($code, $version = false, $checkHash = false, $installHash = false, $from = null) : array
	{
		$result = [];

		if (!OAuthService::getEngine()->isRegistered())
		{
			try
			{
				OAuthService::register();
				OAuthService::getEngine()->getClient()->getApplicationList();
			}
			catch(SystemException $e)
			{
				$result = [
					'error' => $e->getCode(),
					'errorDescription' => $e->getMessage(),
				];
			}
		}

		if (OAuthService::getEngine()->isRegistered())
		{
			$version = !empty($version) ? $version : false;

			$result = [
				'error' => 'INSTALL_ERROR',
				'errorDescription' => Loc::getMessage('RMP_INSTALL_ERROR'),
			];

			$appDetailInfo = false;
			if ($code <> '')
			{
				if (!empty($checkHash) && !empty($installHash))
				{
					$appDetailInfo = Client::getInstall($code, $version, $checkHash, $installHash);
				}
				else
				{
					$appDetailInfo = Client::getInstall($code, $version);
				}

				if ($appDetailInfo)
				{
					$appDetailInfo = $appDetailInfo['ITEMS'];
				}
			}

			if (
				$appDetailInfo
				&& !Access::canInstallApp($appDetailInfo)
			)
			{
				$result = [
					'error' => 'ACTION_ACCESS_DENIED',
					'errorDescription' => Loc::getMessage('RMP_ERROR_ACCESS_DENIED'),
					'helperCode' => Access::getHelperCode(Access::ACTION_INSTALL, Access::ENTITY_TYPE_APP, $appDetailInfo)
				];
			}
			elseif ($appDetailInfo)
			{
				if (CRestUtil::canInstallApplication($appDetailInfo, self::$contextUserId))
				{
					$queryFields = [
						'CLIENT_ID' => $appDetailInfo['APP_CODE'],
						'VERSION' => $appDetailInfo['VER'],
						'BY_SUBSCRIPTION' => $appDetailInfo['BY_SUBSCRIPTION'] ?? 'N',
					];

					if (!empty($checkHash) && !empty($installHash))
					{
						$queryFields['CHECK_HASH'] = $checkHash;
						$queryFields['INSTALL_HASH'] = $installHash;
					}

					$installResult = OAuthService::getEngine()->getClient()->installApplication($queryFields);
					if (isset($installResult['error']) && $installResult['error'] === 'verification_needed')
					{
						if (\Bitrix\Main\ModuleManager::isModuleInstalled('bitrix24'))
						{
							OAuthService::getEngine()->getClient()->getApplicationList();
							$installResult = OAuthService::getEngine()->getClient()->installApplication($queryFields);
						}
						elseif (
							defined('ADMIN_SECTION')
							&& $appDetailInfo['TYPE'] === 'C'
							&& $appDetailInfo['MODE'] === 'S'
							&& mb_strpos($appDetailInfo['CODE'], 'bitrix.') === 0
						)
						{
							$installResult = [
								'result' => [
									'client_id' => $appDetailInfo['APP_CODE'],
									'version' => (int)$appDetailInfo['VER'],
									'status' => AppTable::STATUS_FREE,
									'scope' => array_keys($appDetailInfo['RIGHTS']),
								],
							];
						}
					}

					if (isset($installResult['error']) && $installResult['error'])
					{
						$result['error'] = $installResult['error'];
						$result['errorDescription'] = $installResult['error_description'];
					}
					elseif ($installResult['result'])
					{
						$installCompletionMode = self::resolveInstallCompletionMode($appDetailInfo);
						$appFields = [
							'CLIENT_ID' => $installResult['result']['client_id'],
							'CODE' => $appDetailInfo['CODE'],
							'ACTIVE' => AppTable::ACTIVE,
							'INSTALLED' => $installCompletionMode === AppInstallCompletionMode::Immediate
								? AppTable::INSTALLED
								: AppTable::NOT_INSTALLED,
							'INSTALL_COMPLETION_MODE' => $installCompletionMode->value,
							'URL' => $appDetailInfo['URL'],
							'URL_DEMO' => $appDetailInfo['DEMO_URL'],
							'URL_INSTALL' => $appDetailInfo['INSTALL_URL'],
							'URL_SETTINGS' => $appDetailInfo['SETTINGS_URL'],
							'VERSION' => $installResult['result']['version'],
							'SCOPE' => implode(',', $installResult['result']['scope']),
							'STATUS' => $installResult['result']['status'],
							'SHARED_KEY' => $appDetailInfo['SHARED_KEY'],
							'CLIENT_SECRET' => '',
							'APP_NAME' => $appDetailInfo['NAME'],
							'MOBILE' => $appDetailInfo['BXMOBILE'] === 'Y' ? AppTable::ACTIVE : AppTable::INACTIVE,
							'USER_INSTALL' => CRestUtil::appCanBeInstalledByUser($appDetailInfo) ? AppTable::ACTIVE : AppTable::INACTIVE,
						];

						if (
							$appFields['STATUS'] === AppTable::STATUS_TRIAL
							|| $appFields['STATUS'] === AppTable::STATUS_PAID
						)
						{
							$appFields['DATE_FINISH'] = DateTime::createFromTimestamp($installResult['result']['date_finish']);
						}
						else
						{
							$appFields['DATE_FINISH'] = '';
						}

						//Configuration app
						if (
							(
								$appDetailInfo['TYPE'] === AppTable::TYPE_CONFIGURATION
								&& $appDetailInfo['MODE'] !== AppTable::MODE_SITE
							)
							|| $appDetailInfo['TYPE'] === AppTable::TYPE_BIC_DASHBOARD
						)
						{
							$installCompletionMode = AppInstallCompletionMode::Deferred;
							$appFields['INSTALLED'] = AppTable::NOT_INSTALLED;
							$appFields['INSTALL_COMPLETION_MODE'] = $installCompletionMode->value;
						}

						$shouldFinalizeInstallation = $appFields['INSTALLED'] === AppTable::INSTALLED;
						$existingApp = (new ApplicationProvider())->findByClientId(
							(string)$appFields['CLIENT_ID']
						);
						$appFieldsToSave = $appFields;
						$requiresFinalization = $shouldFinalizeInstallation
							&& ($existingApp === null || $existingApp->isInstalled() !== true);
						if ($requiresFinalization)
						{
							$appFieldsToSave['ACTIVE'] = AppTable::INACTIVE;
							$appFieldsToSave['INSTALLED'] = AppTable::NOT_INSTALLED;
						}

						if ($existingApp !== null)
						{
							$addResult = AppTable::update($existingApp->getId(), $appFieldsToSave);
						}
						else
						{
							$addResult = AppTable::add($appFieldsToSave);
						}

						$appId = $addResult->isSuccess() ? (int)$addResult->getId() : 0;
						if ($appId > 0 && $requiresFinalization)
						{
							$addResult = (new ApplicationInstallationFinalizer())->finalize(
								$appId,
								true,
							);
						}
						if (!$addResult->isSuccess() && $requiresFinalization)
						{
							self::rollbackRemoteInstallation($appFields['CLIENT_ID']);
						}

						if ($addResult->isSuccess())
						{
							if ($existingApp !== null)
							{
								AppLogTable::log($appId, AppLogTable::ACTION_TYPE_UPDATE);
							}
							else
							{
								AppLogTable::log($appId, AppLogTable::ACTION_TYPE_ADD);
							}

							if ($appFields['INSTALLED'] === AppTable::INSTALLED)
							{
								AppLogTable::log($appId, AppLogTable::ACTION_TYPE_INSTALL);
							}

							if (!CRestUtil::isAdmin(self::$contextUserId))
							{
								CRestUtil::notifyInstall($appFields);
							}

							if (isset($appDetailInfo['MENU_TITLE']) && is_array($appDetailInfo['MENU_TITLE']))
							{
								foreach ($appDetailInfo['MENU_TITLE'] as $lang => $langName)
								{
									$appLangFields = array(
										'APP_ID' => $appId,
										'LANGUAGE_ID' => $lang,
										'MENU_NAME' => $langName
									);

									$appLangUpdateFields = array(
										'MENU_NAME' => $langName
									);

									$connection = Main\Application::getConnection();
									$queries = $connection->getSqlHelper()->prepareMerge(
										AppLangTable::getTableName(),
										[
											'APP_ID',
											'LANGUAGE_ID'
										],
										$appLangFields,
										$appLangUpdateFields
									);

									foreach($queries as $query)
									{
										$connection->queryExecute($query);
									}
								}
							}

							EventTable::syncInstallEventHandlers(
								(int)$appId,
								$installCompletionMode,
								(string)($appFields['URL_INSTALL'] ?? '')
							);

							if ($installCompletionMode === AppInstallCompletionMode::Immediate)
							{
								AppTable::install($appId);
							}

							$redirect = false;
							$open = false;
							$sliderUrl = false;
							if (
								$appDetailInfo['TYPE'] !== AppTable::TYPE_CONFIGURATION
								&& $appDetailInfo['TYPE'] !== AppTable::TYPE_BIC_DASHBOARD
							)
							{
								$uriString = CRestUtil::getApplicationPage($appId);
								$uri = new Uri($uriString);
								$ver = (int) $version;
								$uri->addParams(
									[
										'ver' => $ver,
										'check_hash' => $checkHash,
										'install_hash' => $installHash
									]
								);
								$redirect = $uri->getUri();
								$open = !empty($appDetailInfo['URL'])
									&& ($appDetailInfo['OPEN_API'] !== 'Y' || self::hasMenuTitle($appDetailInfo));
							}
							else
							{
								if ((int)$appDetailInfo['IMPORT_ZIP_ID'] > 0)
								{
									$url = Url::getConfigurationImportZipUrl((int)$appDetailInfo['IMPORT_ZIP_ID']);
								}
								else
								{
									$url = Url::getConfigurationImportAppUrl($appDetailInfo['CODE']);
								}

								$uri = new Uri($url);
								if (!empty($checkHash) && !empty($installHash))
								{
									$uri->addParams(
										[
											'check_hash' => $checkHash,
											'install_hash' => $installHash,
										]
									);
								}
								$sliderUrl = $uri->getUri();
							}

							$result = [
								'success' => 1,
								'id' => $appId,
								'open' => $open,
								'installed' => AppTable::isInstalled($appId),
								'redirect' => $redirect,
								'openSlider' => $sliderUrl,
								'canShowForm' => !empty($appFields['URL_SETTINGS'])
							];

							Analytic::logToFile(
								'finishInstall',
								$code,
								$from ?? 'index'
							);
						}
						else
						{
							$result = self::describeInstallationFailure($addResult, $appDetailInfo, $result);
						}
					}
				}
				elseif (
					!empty($appInfo['HOLD_INSTALL_BY_TRIAL'])
					&& $appInfo['HOLD_INSTALL_BY_TRIAL'] === 'Y'
					&& Client::isSubscriptionDemo()
				)
				{
					$result = ['error' => Loc::getMessage('RMP_TRIAL_HOLD_INSTALL_MSGVER_1')];
				}
				else
				{
					$result = [
						'error' => 'ACCESS_DENIED',
						'errorDescription' => Loc::getMessage('RMP_ACCESS_DENIED'),
					];
				}
			}
			else
			{
				$result = [
					'error' => 'APPLICATION_NOT_FOUND',
					'errorDescription' => Loc::getMessage('RMP_NOT_FOUND'),
				];
			}
		}
		elseif (!$result['error'])
		{
			$result = [
				'error' => 'OAUTH_REGISTER',
				'errorDescription' => Loc::getMessage('RMP_INSTALL_ERROR'),
			];
		}

		if (isset($result['error']) && $result['error'])
		{
			if ($result['error'] === 'SUBSCRIPTION_REQUIRED')
			{
				$result['errorDescription'] = Loc::getMessage('RMP_ERROR_SUBSCRIPTION_REQUIRED_MSGVER_1');
			}
			elseif ($result['error'] === 'verification_needed')
			{
				$result['errorDescription'] = Loc::getMessage('RMP_ERROR_VERIFICATION_NEEDED');
			}
		}

		return $result;
	}

	private static function rollbackRemoteInstallation(string $clientId): void
	{
		try
		{
			OAuthService::getEngine()->getClient()->unInstallApplication([
				'CLIENT_ID' => $clientId,
			]);
		}
		catch (\Throwable)
		{
		}
	}

	public static function uninstall($code, bool $clean = false, $from = null) : array
	{
		if (CRestUtil::isAdmin(self::$contextUserId))
		{
			$res = AppTable::getList(
				[
					'filter' => [
						'=CODE' => $code,
						'!=STATUS' => AppTable::STATUS_LOCAL,
					],
				]
			);

			$appInfo = $res->fetch();
			if ($appInfo)
			{
				$checkResult = AppTable::checkUninstallAvailability($appInfo['ID'], $clean);
				if (
					$checkResult->isEmpty()
					&& AppTable::canUninstallByType($appInfo['CODE'], $appInfo['VERSION'])
				)
				{
					AppTable::uninstall($appInfo['ID'], $clean);

					$appFields = [
						'ACTIVE' => 'N',
						'INSTALLED' => 'N',
					];

					AppTable::update($appInfo['ID'], $appFields);

					AppLogTable::log($appInfo['ID'], AppLogTable::ACTION_TYPE_UNINSTALL);

					Analytic::logToFile(
						'finishUninstall',
						$appInfo['CODE'],
						$from ?? 'index'
					);

					$result = ['success' => 1];
				}
				else
				{
					$errorMessage = [];
					foreach ($checkResult as $error)
					{
						$errorMessage[] = $error->getMessage();
					}

					$result = ['error' => implode(PHP_EOL, $errorMessage)];
					$appType = AppTable::getAppType($appInfo['CODE']);
					if (
						$checkResult->isEmpty()
						&& ($appType === AppTable::TYPE_CONFIGURATION || $appType === AppTable::TYPE_BIC_DASHBOARD)
					)
					{
						$result = [
							'sliderUrl' => \Bitrix\Rest\Marketplace\Url::getConfigurationImportRollbackUrl(
								$appInfo['CODE']
							),
						];
					}
				}
			}
			else
			{
				$result = ['error' => Loc::getMessage('RMP_NOT_FOUND')];
			}
		}
		else
		{
			$result = ['error' => Loc::getMessage('RMP_ACCESS_DENIED')];
		}

		return $result;
	}

	public static function reinstall($id) : array
	{
		$result = [];
		if (CRestUtil::isAdmin(self::$contextUserId))
		{
			$appInfo = AppTable::getByClientId($id);
			if (
				!Access::isAvailable($id)
				|| !Access::isAvailableCount(Access::ENTITY_TYPE_APP, $id)
			)
			{
				$result = [
					'error' => Loc::getMessage('RMP_ERROR_ACCESS_DENIED'),
					'helperCode' => Access::getHelperCode(Access::ACTION_INSTALL, Access::ENTITY_TYPE_APP, $appInfo)
				];
			}
			elseif ($appInfo && $appInfo['STATUS'] === AppTable::STATUS_LOCAL)
			{
				// delete user application params to trigger installation event
				\CUserOptions::DeleteOption('app_options', 'params_' . $appInfo['CLIENT_ID'] . '_' . $appInfo['VERSION']);

				$installCompletionMode = AppInstallCompletionMode::tryFrom(
					(string)($appInfo['INSTALL_COMPLETION_MODE'] ?? '')
				) ?? (
					empty($appInfo['URL_INSTALL'])
						? AppInstallCompletionMode::Immediate
						: AppInstallCompletionMode::Deferred
				);

				if ($installCompletionMode === AppInstallCompletionMode::Immediate)
				{
					$updateResult = AppTable::update(
						$appInfo['ID'],
						[
							'INSTALLED' => AppTable::INSTALLED,
						]
					);
					if ($updateResult->isSuccess())
					{
						AppTable::install($appInfo['ID']);
						$result = ['success' => 1];
					}
					else
					{
						$result = ['error' => Loc::getMessage('RMP_INSTALL_ERROR')];
					}
				}
				else
				{
					$updateResult = AppTable::update(
						$appInfo['ID'],
						[
							'INSTALLED' => AppTable::NOT_INSTALLED,
						]
					);
					if ($updateResult->isSuccess())
					{
						$result = ['success' => 1];

						// deferred completion happens inside the application page, so an application
						// without a page has nowhere to send the admin to
						$hasApplicationPage = !AppInstallCompletionMode::isApiOnly([
							$appInfo['MENU_NAME'],
							$appInfo['MENU_NAME_DEFAULT'],
							$appInfo['MENU_NAME_LICENSE'],
						]);
						if ($hasApplicationPage)
						{
							$result['redirect'] = CRestUtil::getApplicationPage($appInfo['ID']);
						}
					}
					else
					{
						$result = ['error' => Loc::getMessage('RMP_INSTALL_ERROR')];
					}
				}
			}
			else
			{
				$result = ['error' => Loc::getMessage('RMP_NOT_FOUND')];
			}
		}
		else
		{
			$result = ['error' => Loc::getMessage('RMP_ACCESS_DENIED')];
		}

		return $result;
	}

	public static function setRights($appId, $rights) : array
	{
		if ($appId <= 0)
		{
			return [];
		}

		$appInfo = (new ApplicationProvider())->findById((int)$appId);
		if ($appInfo === null)
		{
			return [];
		}

		if ((string)$appInfo->getCode() !== '')
		{
			Analytic::logToFile('setAppRight', $appInfo->getCode(), $appInfo->getCode());
		}

		$codes = [];
		if (is_array($rights) && !empty($rights))
		{
			foreach ($rights as $rightsList)
			{
				foreach ($rightsList as $rightId => $ar)
				{
					$codes[] = $rightId;
				}
			}
		}

		$userId = self::$contextUserId ?? (int)($GLOBALS['USER']?->GetID() ?? 0);

		try
		{
			$commandResult = (new SetAppAccessCommand(
				userId: $userId,
				clientId: (string)$appInfo->getClientId(),
				accessCodes: $codes,
			))->run();
		}
		catch (Main\Command\Exception\CommandException | Main\Command\Exception\CommandValidationException)
		{
			return ['error' => Loc::getMessage('RMP_ACCESS_DENIED')];
		}

		if (!$commandResult->isSuccess())
		{
			return ['error' => Loc::getMessage('RMP_ACCESS_DENIED')];
		}

		return ['success' => 1];
	}

	public static function getRights($appId)
	{
		if ($appId <= 0)
		{
			return 0;
		}

		$appRepository = new AppRepository();
		$app = $appRepository->getById((int)$appId);
		if ($app === null)
		{
			return 0;
		}

		$userId = self::$contextUserId ?? (int)($GLOBALS['USER']?->GetID() ?? 0);
		$accessChecker = new AppAccessChecker($userId);

		if (!$accessChecker->canManageAppAccess($app))
		{
			return ['error' => Loc::getMessage('RMP_ACCESS_DENIED')];
		}

		return AppTable::getAccess($appId);
	}
}
