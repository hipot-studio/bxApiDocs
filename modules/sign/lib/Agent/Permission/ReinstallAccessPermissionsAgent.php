<?php

namespace Bitrix\Sign\Agent\Permission;

use Bitrix\Sign\Access\Install\PermissionInstallOutcome;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Access;

class ReinstallAccessPermissionsAgent
{
	public static function run(): string
	{
		if (!Container::instance()->getDocumentRepository()->existAnyDocument())
		{
			// Whether the portal carries no permissions at all is decided inside, under the lock that guards
			// the write: decided out here, the answer would go stale while the install waits for the lock, and
			// the defaults would overwrite the rights the writer holding it has just stored.
			$outcome = Access\Install\AccessInstaller::installDefaultsForPortalWithoutPermissions();

			if ($outcome === PermissionInstallOutcome::Deferred)
			{
				// Nothing was written and the install is still due. An agent run returning an empty line is
				// taken off the schedule, so the line has to keep this agent on it.
				return self::getRetryCall();
			}

			if ($outcome === PermissionInstallOutcome::Installed)
			{
				return '';
			}

			// NotNeeded: the portal already carries rows, so the two installers below are what it needs -
			// they add the rows missing from the default roles and leave every stored value alone.
		}

		// Each install is asked for on its own and the first refusal ends the run: both wait for the same
		// lock, so asking the second one after the first was refused would hold a web server process for
		// another full wait to arrive at the very same answer.
		if (!Access\Install\AccessInstaller::installMissingSafeFolderPermissions())
		{
			return self::getRetryCall();
		}

		if (!Access\Install\AccessInstaller::installMissingDocumentAnnulPermission())
		{
			return self::getRetryCall();
		}

		return '';
	}

	/**
	 * The line that keeps this agent on the schedule. The leading slash keeps it in the shape the agent is
	 * registered in.
	 */
	private static function getRetryCall(): string
	{
		return '\\' . self::class . '::run();';
	}
}
