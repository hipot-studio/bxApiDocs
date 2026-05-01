<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper\Task;

use Bitrix\Tasks\V2\Internal\Entity\Task\AccessRequest;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Trait\CastTrait;

class AccessRequestMapper
{
	use CastTrait;

	public function mapFromEntity(AccessRequest $accessRequest): array
	{
		return [
			'TASK_ID' => $accessRequest->taskId,
			'USER_ID' => $accessRequest->userId,
			'CREATED_DATE' => $this->castTimestamp($accessRequest->createdDateTs),
		];
	}

	public function mapToEntity(array $accessRequest): AccessRequest
	{
		return AccessRequest::mapFromArray([
			'taskId' => $accessRequest['TASK_ID'] ?? null,
			'userId' => $accessRequest['USER_ID'] ?? null,
			'createdDateTs' => $this->castDateTime($accessRequest['CREATED_DATE'] ?? null),
		]);
	}
}
