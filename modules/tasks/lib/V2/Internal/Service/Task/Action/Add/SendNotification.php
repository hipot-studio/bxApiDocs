<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Task\Trait\OccurredUserTrait;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Async\Message;

class SendNotification
{
	use ConfigTrait;
	use OccurredUserTrait;

	public function __invoke(array $fields): void
	{
		$config = [
			'SPAWNED_BY_AGENT' => $this->config->isFromAgent(),
			'SPAWNED_BY_WORKFLOW' => $this->config->isFromWorkFlow(),
			'AUTHOR_ID' => $this->getOccurredUserId($this->config->getUserId()),
		];

		(new Message\AddSendNotification(
			taskId: $fields['ID'],
			config: $config,
		))->sendByTaskId($fields['ID']);
	}
}
