<?php

namespace Bitrix\BIConnectorMobile;

class Workspace
{
	/**
	 * Returns the path from the document root to the mobile BI workspace.
	 *
	 * @return string
	 */
	public static function getPath(): string
	{
		return '/bitrix/mobileapp/biconnectormobile/';
	}
}
