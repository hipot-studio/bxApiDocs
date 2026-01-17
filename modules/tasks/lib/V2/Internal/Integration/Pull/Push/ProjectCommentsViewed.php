<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Pull\Push;

use Bitrix\Tasks\Integration\Pull\PushCommand;

/**
 * @method self payload(int $userId, ?int $groupId = null)
 */
class ProjectCommentsViewed extends AbstractPayload
{
	public function __construct
	(
		public int $userId,
		public ?int $groupId = null,
	)
	{
	}

	public function getCommand(): string
	{
		return PushCommand::PROJECT_COMMENTS_VIEWED;
	}

	/** @return array{USER_ID: int, GROUP_ID: int|null} */
	public function toArray(): array
	{
		return [
			'USER_ID' => $this->userId,
			'GROUP_ID' => $this->groupId,
		];
	}
}
