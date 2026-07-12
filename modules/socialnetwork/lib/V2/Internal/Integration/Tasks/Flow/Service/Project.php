<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Flow\Service;

use Bitrix\Socialnetwork\Integration\Tasks\Flow\FlowFeature;
use Bitrix\Tasks\Flow\Provider\ProjectProvider;

class Project
{
	public function hasFlows(int $projectId): bool
	{
		if (
			$projectId <= 0
			|| !FlowFeature::isOn()
			|| !class_exists(ProjectProvider::class)
		)
		{
			return false;
		}

		return (new ProjectProvider())->hasFlows($projectId);
	}
}
