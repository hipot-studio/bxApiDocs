<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\WorkflowTemplateLockService;

use Bitrix\Bizproc\Result;

final class AcquireLockResponse extends Result
{
	/**
	 * The outcome of the request: whether the template is leased to the user that asked for it. It is read
	 * from the state of the lease after the operation, not from the number of rows written.
	 *
	 * A failed response holds nothing either: an unavailable storage is told from a refusal by isSuccess().
	 */
	public function isHeld(): bool
	{
		return $this->data['isHeld'] ?? false;
	}

	public function setHeld(bool $isHeld): self
	{
		$this->data['isHeld'] = $isHeld;

		return $this;
	}

	/**
	 * @return LockOwnerDto|null the owner of the lease, whoever it is; null when the template has no lease
	 * row anymore
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
