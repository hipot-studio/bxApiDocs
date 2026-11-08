<?php

namespace Bitrix\Sign\Access\Install;

/**
 * What an install of the default sign permissions did. The three outcomes are told apart because the agent
 * asking for the install has to act on each one differently: keep itself on the schedule, stop, or go on
 * to the installers of the rows missing from the roles that already exist.
 */
enum PermissionInstallOutcome
{
	/** The defaults are stored. */
	case Installed;

	/**
	 * The install is not the operation this portal needs: it already carries permission rows, so writing
	 * the defaults over them would drop what an administrator has set up.
	 */
	case NotNeeded;

	/**
	 * Nothing was written and the install is still due - the write lock belonged to another writer, or the
	 * CRM roles it writes for do not exist yet. The caller has to ask for another run.
	 */
	case Deferred;
}
