<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\WorkflowTemplateLockService;

use Bitrix\Bizproc\Result;

final class GetLockResponse extends Result
{
	/**
	 * @return LockOwnerDto|null null when nobody edits the template right now
	 */
	public function getLock(): ?LockOwnerDto
	{
		return $this->data['lock'] ?? null;
	}

	public function setLock(?LockOwnerDto $lock): self
	{
		$this->data['lock'] = $lock;

		return $this;
	}
}
