<?php

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Response\Task\Access;

use Bitrix\Rest\V3\Interaction\Response\GetResponse;

class GetTaskAccessResponse extends GetResponse
{
	public function __construct(int $taskId, int $userId, ?array $rights)
	{
		$this->item = [
			'taskId' => $taskId,
			'userId' => $userId,
			'rights' => $rights,
		];
	}
}
