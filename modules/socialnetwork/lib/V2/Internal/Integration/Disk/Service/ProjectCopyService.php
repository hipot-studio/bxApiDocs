<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Disk\Service;

use Bitrix\Disk;
use Bitrix\Main\Loader;

class ProjectCopyService
{
	public function copy(
		int $userId,
		int $sourceProjectId,
		int $targetProjectId,
		array $features,
	): void
	{
		if (!$this->isModuleAvailable())
		{
			return;
		}

		$feature = new Disk\Copy\Integration\Group($userId, $features);
		$feature->copy($sourceProjectId, $targetProjectId);
	}

	private function isModuleAvailable(): bool
	{
		return Loader::includeModule('disk')
			&& Disk\Driver::isSuccessfullyConverted();
	}
}
