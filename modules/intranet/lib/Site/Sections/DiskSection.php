<?php

namespace Bitrix\Intranet\Site\Sections;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

class DiskSection
{
	public static function getSubmenuItems(): array
	{
		$userId = (int)CurrentUser::get()->getId();
		$diskEnabled = static::isDiskEnabled();
		$diskPath = static::getUserDiskPath($userId, $diskEnabled);

		$items = [
			static::createMenuItem(
				'menu_my_disk',
				'MENU_DISK_USER',
				$diskPath,
			),
			static::createMenuItem(
				'menu_common_disk',
				'MENU_DISK_COMMON',
				SITE_DIR . 'docs/path/',
			),
			static::createMenuItem(
				'menu_windows_disk',
				'MENU_DISK_WINDOWS_APP',
				SITE_DIR . 'docs/windows.php',
			),
			static::createMenuItem(
				'menu_macos_disk',
				'MENU_DISK_MACOS_APP',
				SITE_DIR . 'docs/macos.php',
			),
		];

		if ($diskEnabled)
		{
			$items[] = static::createMenuItem(
				'menu_my_disk_volume',
				'MENU_DISK_VOLUME',
				SITE_DIR . "company/personal/user/{$userId}/disk/volume/",
			);
		}

		return $items;
	}

	protected static function isDiskEnabled(): bool
	{
		return Option::get('disk', 'successfully_converted', 'N') === 'Y';
	}

	private static function getUserDiskPath(int $userId, bool $diskEnabled): string
	{
		if ($diskEnabled)
		{
			return SITE_DIR . "company/personal/user/{$userId}/disk/path/";
		}

		return SITE_DIR . "company/personal/user/{$userId}/files/lib/";
	}

	private static function createMenuItem(string $id, string $messageKey, string $url, ?string $counter = null, ?string $counterId = null): array
	{
		return [
			'ID' => $id,
			'TEXT' => Loc::getMessage('DISK_SECTION_' . $messageKey) ?: '',
			'URL' => $url,
			'COUNTER' => $counter ?? '',
			'COUNTER_ID' => $counterId ?? '',
		];
	}
}
