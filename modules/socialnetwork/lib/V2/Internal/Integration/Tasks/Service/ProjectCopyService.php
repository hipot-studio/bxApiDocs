<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Service;

use Bitrix\Main\Loader;
use Bitrix\Tasks;

class ProjectCopyService
{
	public function copy(
		int $userId,
		int $sourceProjectId,
		int $targetProjectId,
		array $features,
		?array $datesPayload,
	): void
	{
		if (!$this->isModuleAvailable())
		{
			return;
		}

		$feature = new Tasks\Copy\Integration\Group($userId, $features);

		if ($datesPayload !== null)
		{
			$feature->setProjectTerm($datesPayload);
		}

		$feature->copy($sourceProjectId, $targetProjectId);
	}

	private function isModuleAvailable(): bool
	{
		return Loader::includeModule('tasks');
	}
}
