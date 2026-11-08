<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\WorkflowTemplateLockService;

use Bitrix\Bizproc\Result;

final class ReleaseLockResponse extends Result
{
	/**
	 * @return bool false when there was nothing to release: no lease at all, or a lease of another user an
	 * ordinary caller may not touch
	 */
	public function isReleased(): bool
	{
		return $this->data['released'] ?? false;
	}

	public function setReleased(bool $isReleased): self
	{
		$this->data['released'] = $isReleased;

		return $this;
	}
}
