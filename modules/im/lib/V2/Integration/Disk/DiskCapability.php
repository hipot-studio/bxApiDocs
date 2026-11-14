<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Disk;

use Bitrix\Disk\Internal\Service\Quota\UploadQuotaScenario;
use Bitrix\Disk\Internal\Service\Quota\UploadQuotaStopInfo;
use Bitrix\Disk\Internal\Service\UploadCleanup\CleanupGuard;
use Bitrix\Disk\Internal\Service\UploadCleanup\MarkerService;
use Bitrix\Disk\Search\HeadIndexOperationResult;
use Bitrix\Disk\Search\IndexManager;
use Bitrix\Main\Loader;

/**
 * Disk before 26.1400.0 has none of the classes and methods checked here, and the im update does not require it.
 * Drop the checks once im requires disk 26.1400.0 or newer.
 */
final class DiskCapability
{
	private static ?bool $uploadLimitAvailable = null;
	private static ?bool $headIndexAvailable = null;

	public static function isUploadLimitAvailable(): bool
	{
		self::$uploadLimitAvailable ??=
			Loader::includeModule('disk')
			&& class_exists(MarkerService::class)
			&& class_exists(CleanupGuard::class)
			&& class_exists(UploadQuotaScenario::class)
			&& class_exists(UploadQuotaStopInfo::class);

		return self::$uploadLimitAvailable;
	}

	public static function isHeadIndexAvailable(): bool
	{
		self::$headIndexAvailable ??=
			Loader::includeModule('disk')
			&& class_exists(HeadIndexOperationResult::class)
			&& method_exists(IndexManager::class, 'publishFileToHeadIndex')
			&& method_exists(IndexManager::class, 'updateFileHeadIndexIfExists');

		return self::$headIndexAvailable;
	}
}
