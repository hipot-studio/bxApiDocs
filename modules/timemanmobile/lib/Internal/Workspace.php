<?php

namespace Bitrix\TimemanMobile\Internal;

class Workspace
{
	/**
	 * Returns the path from the root directory to mobile timeman workspace (timemanmobile)
	 *
	 * @return string
	 */
	public static function getPath(): string
	{
		return '/bitrix/mobileapp/timemanmobile/';
	}
}
