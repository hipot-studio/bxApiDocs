<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Scrum;

use Bitrix\Main\Result;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class UpdateScrumTagsHandler
{
	public function __invoke(UpdateScrumTagsCommand $command): Result
	{
		return Container::getInstance()->getWorkgroupTagService()->updateTags(
			groupId: $command->scrumId,
			tags: $command->tags,
		);
	}
}
