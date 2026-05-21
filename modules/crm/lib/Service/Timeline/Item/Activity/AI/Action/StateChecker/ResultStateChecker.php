<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\StateChecker;

use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIOperationStateChecker;

final readonly class ResultStateChecker implements AIOperationStateChecker
{
	public function __construct(private Result $result) {}

	public function isPending(): bool
	{
		return $this->result->isPending();
	}

	public function isSuccess(): bool
	{
		return $this->result->isSuccess();
	}

	public function isErrorsLimitExceeded(): bool
	{
		return $this->result->isErrorsLimitExceeded();
	}
}
