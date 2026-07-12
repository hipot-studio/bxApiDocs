<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Main\Result;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class UpdateProjectTagsHandler
{
	public function __invoke(UpdateProjectTagsCommand $command): Result
	{
		return Container::getInstance()->getWorkgroupTagService()->updateTags(
			groupId: $command->projectId,
			tags: $command->tags,
		);
	}
}
