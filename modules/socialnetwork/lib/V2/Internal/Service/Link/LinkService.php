<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Link;

use Bitrix\Socialnetwork\Site\GroupUrl;

class LinkService
{
	public function getForProject(int $projectId): string
	{
		return GroupUrl::get($projectId);
	}
}
