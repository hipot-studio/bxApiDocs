<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\StaffTrack;

use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Public\Provider\OfficesTileProvider;

final class OfficesTile
{
	/**
	 * @return array{count: int, url: string}|null
	 */
	public static function get(): ?array
	{
		if (!Loader::includeModule('stafftrack'))
		{
			return null;
		}

		try
		{
			return (new OfficesTileProvider())->getTile();
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
