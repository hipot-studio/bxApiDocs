<?php

namespace Bitrix\BIConnector\Superset\Updater\Versions;

use Bitrix\Main\Result;

/**
 * Version 6 does nothing.
 * It is just to have the last version number updated.
 * Staying for backward compatibility.
 */
final class Version6 extends BaseVersion
{
	public function run(): Result
	{
		return new Result();
	}
}
