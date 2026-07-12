<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler;

use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\V2\Infrastructure\Agent\GroupTaskChatLinker;

class UpdateGroupTasksChat implements HandlerInterface
{
	public function __invoke(Workgroup $group): void
	{
		GroupTaskChatLinker::bind(
			delay: 0,
			withArguments: [
				$group->getId(),
				$group->getChatId(),
			],
		);
	}
}
