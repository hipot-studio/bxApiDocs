<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Flow\Service;

use Bitrix\Socialnetwork\Integration\Tasks\Flow\Path\FlowPath;

class Path
{
	public function get(int $userId, int $projectId): string
	{
		return FlowPath::get($userId, $projectId);
	}

	public function getTemplate(int $userId): string
	{
		return FlowPath::getTemplate($userId);
	}
}
