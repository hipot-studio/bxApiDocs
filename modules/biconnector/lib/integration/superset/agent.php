<?php

namespace Bitrix\BIConnector\Integration\Superset;

use Bitrix\BIConnector\Access\Install\AccessInstaller;
use Bitrix\BIConnector\Access\Role\RoleTable;
use Bitrix\BIConnector\Access\Service\SystemGroupLocalizationService;
use Bitrix\BIConnector\Access\Update\DashboardGroupRights\Converter;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupTable;
use Bitrix\BIConnector\Superset\Logger\MarketDashboardLogger;
use Bitrix\BIConnector\Superset\SystemDashboardManager;
use Bitrix\BIConnector\Superset\UI\DashboardManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\LanguageTable;
use Bitrix\Main\Localization\Loc;

class Agent
{
	private const IS_ACCESS_ROLES_REINSTALLED_OPTION = 'is_access_roles_reinstalled';

	/**
	 * @deprecated
	 */
	public static function setDashboardOwners(): string
	{
		return '';
	}

	/**
	 * @deprecated
	 *
	 * Sets admin as dashboard's owner if the previous owner was fired.
	 *
	 * @param int $previousOwnerId User id of previous owner.
	 *
	 * @return string
	 */
	public static function setDefaultOwnerForDashboards(int $previousOwnerId): string
	{
		return '';
	}

	/**
	 * Send request to delete superset instance.
	 *
	 * @return string
	 */
	public static function deleteInstance(): string
	{
		Superset\SupersetInitializer::deleteInstance();

		return '';
	}

	/**
	 * Notify admins that BI Constructor will be deleted soon.
	 * Scheduled by pendingDeleteInstance(), fires 1 day before actual deletion.
	 *
	 * @return string Empty string to remove agent from schedule.
	 */
	public static function pendingDeleteNotify(): string
	{
		if (!Superset\SupersetInitializer::isSupersetPendingDelete())
		{
			return '';
		}

		$titleCallback = static fn(?string $languageId = null) => Loc::getMessage(
			'BI_SUPERSET_PENDING_DELETE_NOTIFY_TITLE',
			language: $languageId,
		);
		$notificationCallback = static fn(?string $languageId = null) => Loc::getMessage(
			'BI_SUPERSET_PENDING_DELETE_NOTIFY_TEXT',
			language: $languageId,
		);

		self::notifyAllAdmins($titleCallback, $notificationCallback);

		return '';
	}

	public static function notifyPendingDeleteCompleted(): void
	{
		$titleCallback = static fn(?string $languageId = null) => Loc::getMessage(
			'BI_SUPERSET_PENDING_DELETE_COMPLETED_TITLE',
			language: $languageId,
		);
		$notificationCallback = static fn(?string $languageId = null) => Loc::getMessage(
			'BI_SUPERSET_PENDING_DELETE_COMPLETED_TEXT',
			language: $languageId,
		);

		self::notifyAllAdmins($titleCallback, $notificationCallback);
	}

	private static function notifyAllAdmins(callable $titleCallback, callable $messageCallback): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$adminIds = self::getPortalAdminIds();

		foreach ($adminIds as $adminId)
		{
			\CIMNotify::Add([
				'TO_USER_ID' => $adminId,
				'FROM_USER_ID' => 0,
				'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
				'NOTIFY_MODULE' => 'biconnector',
				'NOTIFY_TITLE' => $titleCallback,
				'NOTIFY_MESSAGE' => $messageCallback,
			]);
		}
	}

	private static function getPortalAdminIds(): array
	{
		if (Loader::includeModule('bitrix24'))
		{
			return \CBitrix24::getAllAdminId();
		}

		$adminIds = [];
		$res = \CGroup::GetGroupUserEx(1);
		while ($admin = $res->fetch())
		{
			$adminIds[] = (int)$admin['USER_ID'];
		}

		return $adminIds;
	}

	/**
	 * Deferred deletion: check if still PENDING_DELETE and perform actual deletion.
	 * Scheduled by pendingDeleteInstance(), fires after N days.
	 *
	 * @return string Empty string to remove agent from schedule.
	 */
	public static function pendingDeleteCheck(): string
	{
		if (!Superset\SupersetInitializer::isSupersetPendingDelete())
		{
			return '';
		}

		$deleteResult = Superset\SupersetInitializer::deleteInstance();
		if ($deleteResult->isSuccess())
		{
			return '';
		}

		// Retry on next cron tick until deletion succeeds or status changes
		return '\\' . __CLASS__ . '::' . __FUNCTION__ . '();';
	}

	/**
	 * Create default roles using agent after installing the module in a new portal.
	 *
	 * @return string
	 */
	public static function createDefaultRoles(): string
	{
		if (RoleTable::getCount() > 0)
		{
			return '';
		}

		$connection = \Bitrix\Main\Application::getInstance()->getConnection();
		$connection->startTransaction();

		AccessInstaller::install();
		Option::set('biconnector', Feature::CHECK_PERMISSION_BY_GROUP_OPTION, 'Y');
		MarketDashboardLogger::logInfo('createDefaultRoles: start actualizeSystemDashboards', [
			'group_option_status' => Feature::isCheckPermissionsByGroup() ? 'Y' : 'N',
			'count_system_groups' => SupersetDashboardGroupTable::getCount([
				'=TYPE' => SupersetDashboardGroupTable::GROUP_TYPE_SYSTEM,
			]),
		]);
		SystemDashboardManager::actualizeSystemDashboards();

		$connection->commitTransaction();

		return '';
	}

	public static function reinstallRoles(): string
	{
		$isAccessRolesReinstalled = Option::get('biconnector', self::IS_ACCESS_ROLES_REINSTALLED_OPTION, 'N');
		if ($isAccessRolesReinstalled === 'Y')
		{
			return '';
		}

		Option::set('biconnector', self::IS_ACCESS_ROLES_REINSTALLED_OPTION, 'Y');
		AccessInstaller::reinstall();

		return '';
	}

	/**
	 * Set default roles using agent after updating the module in an existed portal.
	 *
	 * @return string
	 */
	public static function installDefaultRoles(): string
	{
		if (RoleTable::getCount() === 0)
		{
			AccessInstaller::install(false);
		}

		return '';
	}

	/**
	 * Convert from separate dashboard rights to group dashboard rights.
	 *
	 * @return string
	 */
	public static function convertToGroupDashboardRights(): string
	{
		if (SupersetInitializer::isSupersetExist() && !Feature::isCheckPermissionsByGroup())
		{
			Converter::updateToGroup(true);
		}

		return '';
	}

	/**
	 * Restore default values for group names.
	 *
	 * @return string
	 */
	public static function restoreSystemDashboardGroupNames(): string
	{
		if (Loader::includeModule('bitrix24'))
		{
			$defaultLanguage = \CBitrix24::getLicensePrefix();
		}
		else
		{
			$defaultLanguage = LanguageTable::getList([
				'select' => ['ID'],
				'filter' => ['=ACTIVE' => 'Y', '=DEF' => 'Y'],
			])
				->fetch()['ID'] ?? null
			;
		}

		SystemGroupLocalizationService::update($defaultLanguage);

		return '';
	}

	public static function actualizeSystemDashboards(): string
	{
		if (!SupersetInitializer::isSupersetDeleted() && !SupersetInitializer::isSupersetPendingDelete())
		{
			SystemDashboardManager::actualizeSystemDashboards();
		}

		return __CLASS__ . '::' . __FUNCTION__ . '();';
	}

	/**
	 * One-shot recovery for clients stuck in DELETED status due to race between
	 * onDisableBiBuilderTool() and the portal delete callback (task 0244532).
	 *
	 * @return string
	 */
	public static function recoverStuckDeletedStatus(): string
	{
		if (Option::get('biconnector', 'stuck_deleted_status_recovered', 'N') === 'Y')
		{
			return '';
		}

		if (SupersetInitializer::getSupersetStatus() !== SupersetInitializer::SUPERSET_STATUS_DELETED)
		{
			Option::set('biconnector', 'stuck_deleted_status_recovered', 'Y');

			return '';
		}

		SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS);
		AccessInstaller::install();
		Option::set('biconnector', Feature::CHECK_PERMISSION_BY_GROUP_OPTION, 'Y');
		SystemDashboardManager::actualizeSystemDashboards();
		DashboardManager::notifySupersetStatus(SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS);

		Option::set('biconnector', 'stuck_deleted_status_recovered', 'Y');

		return '';
	}

	/**
	 * Safety net for the rebind disable flow.
	 * @see SupersetInitializer::onDisableBiBuilderTool()
	 *
	 * @return string
	 */
	public static function recoverDeletedAfterRebindTimeout(): string
	{
		if (SupersetInitializer::getSupersetStatus() !== SupersetInitializer::SUPERSET_STATUS_DELETED)
		{
			return '';
		}

		SupersetInitializer::markSupersetInstanceExists(false);
		SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS);
		DashboardManager::notifySupersetStatus(SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS);

		return '';
	}
}
