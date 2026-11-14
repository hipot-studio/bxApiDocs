<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Catalog;

use Bitrix\Catalog\Access\AccessController;
use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Catalog\Access\Install\AccessInstaller\InstallStatus;
use Bitrix\Catalog\Config\Feature;
use Bitrix\Catalog\Config\State;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

/**
 * The single point CRM V2 asks the catalog about the rights of a user.
 *
 * The user is always an argument: the controller of the catalog is taken by identifier and never by
 * {@see AccessController::getCurrent()}, because the code that asks acts on behalf of somebody who
 * is not the one the process runs as.
 *
 * The catalog module is an optional dependency of CRM, so every method that touches it loads it
 * itself: a caller that did not is answered by a named exception and never by the fatal error of an
 * autoload that found nothing. {@see isModuleAvailable()} is how a caller asks beforehand - what a
 * portal without the catalog is to be told is a matter of what that caller is collecting, and this
 * class has no way to guess it.
 *
 * @internal
 */
class CatalogPermissions
{
	public function isModuleAvailable(): bool
	{
		return Loader::includeModule('catalog');
	}

	/**
	 * Which of its branches the access controller of the catalog will take, read of the same three
	 * sources it branches by and in the same order: the installation of the rights is told first, the
	 * external catalog next, the rule of the action last.
	 *
	 * @throws LoaderException When the catalog is not installed: a module that is not there is in no
	 * state at all, and none of the states of this one would be the truth about it.
	 */
	public function permissionState(): CatalogPermissionState
	{
		Loader::requireModule('catalog');

		if (InstallStatus::inProgress())
		{
			return CatalogPermissionState::CatalogRightsMigration;
		}

		if (State::isExternalCatalog())
		{
			return CatalogPermissionState::ExternalCatalog;
		}

		if (!Feature::isAccessControllerCheckingEnabled())
		{
			return CatalogPermissionState::AccessChecksDisabled;
		}

		return CatalogPermissionState::Regular;
	}

	/**
	 * Whether this user may read the catalog. What the answer is worth depends on the state the portal
	 * is in - {@see permissionState()} tells the caller which one it is.
	 *
	 * @throws LoaderException When the catalog is not installed: there is nothing to grant or to refuse,
	 * which is not the same fact as a refusal.
	 */
	public function canRead(int $userId): bool
	{
		Loader::requireModule('catalog');

		return AccessController::getInstance($userId)->check(ActionDictionary::ACTION_CATALOG_READ);
	}
}
