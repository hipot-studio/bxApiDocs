<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Disk;

use Bitrix\Disk\Internal\Service\Quota\UploadQuotaScenario;
use Bitrix\Disk\Storage;

final class UploadQuotaService
{
	public function detectStop(Storage $storage, int $totalSize): ?UploadQuotaStop
	{
		if (!DiskCapability::isUploadLimitAvailable())
		{
			return null;
		}

		$stopInfo = (new UploadQuotaScenario())->detectForUpload($storage, $totalSize);

		return $stopInfo === null ? null : new UploadQuotaStop($stopInfo->getInfoHelperCode());
	}
}
