<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Main\Web\Uri;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Service\Link\LinkService;

class ChatActionLinkService
{
	public function __construct(private readonly LinkService $linkService)
	{
	}

	public function get(
		Task $task,
		int $userId,
		ChatAction $action,
		int $entityId = 0,
		array $params = [],
	): string
	{
		$taskLink = $this->linkService->get($task, $userId);

		$queryParams = [ChatActionParam::ChatAction->value => $action->value];

		if ($entityId > 0)
		{
			$queryParams[ChatActionParam::EntityId->value] = $entityId;
		}

		if (!empty($params))
		{
			$queryParams = array_merge($queryParams, $params);
		}

		return (new Uri($taskLink))
			->addParams($queryParams)
			->getUri()
		;
	}
}
