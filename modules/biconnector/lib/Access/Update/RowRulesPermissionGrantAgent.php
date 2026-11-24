<?php

namespace Bitrix\BIConnector\Access\Update;

use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * One-shot converter of an update: hands the right to edit row rules over to the roles that reach the analyst
 * workspace, see RowRulesPermissionGrant.
 *
 * Registered with addIfModuleExists(), so a module removed with its data kept gets the right on its next
 * installation. The agent stays in the schedule until the write succeeds; a role that already has a row of the
 * right, whatever its value, is skipped, so a repeated pass grants nothing twice.
 */
final class RowRulesPermissionGrantAgent
{
	public const AGENT_NAME = 'Bitrix\\BIConnector\\Access\\Update\\RowRulesPermissionGrantAgent::run();';

	/**
	 * @return string Own name to stay in the schedule, an empty string to be removed from it.
	 */
	public static function run(): string
	{
		if (!Loader::includeModule('biconnector'))
		{
			return self::AGENT_NAME;
		}

		return self::getNextCall(RowRulesPermissionGrant::grant());
	}

	/**
	 * @param Result $grantResult Result of RowRulesPermissionGrant::grant().
	 *
	 * @return string Own name while the grant failed, an empty string once it succeeded.
	 */
	public static function getNextCall(Result $grantResult): string
	{
		// The grant writes its own failure to the log, so it is not repeated here.
		return $grantResult->isSuccess() ? '' : self::AGENT_NAME;
	}
}
