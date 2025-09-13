<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Task;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;

class View extends AbstractEntity
{
	public function __construct(
		public readonly ?int $taskId = null,
		public readonly ?int $userId = null,
		public readonly ?int $viewedTs = null,
		public readonly ?bool $isRealView = null,
	)
	{

	}

	public function getId(): array
	{
		return [
			'TASK_ID' => $this->taskId,
			'USER_ID' => $this->userId,
		];
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			taskId: $props['taskId'] ?? null,
			userId: $props['userId'] ?? null,
			viewedTs: $props['viewedTs'] ?? null,
			isRealView: $props['isRealView'] ?? null,
		);
	}

	public function toArray(): array
	{
		return [
			'taskId' => $this->taskId,
			'userId' => $this->userId,
			'viewedTs' => $this->viewedTs,
			'isRealView' => $this->isRealView,
		];
	}
}