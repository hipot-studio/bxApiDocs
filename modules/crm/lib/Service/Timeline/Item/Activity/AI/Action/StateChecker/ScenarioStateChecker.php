<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\StateChecker;

use Bitrix\Crm\Integration\AI\Operation\OperationState;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIOperationStateChecker;
use Closure;

final readonly class ScenarioStateChecker implements AIOperationStateChecker
{
	public function __construct(
		private OperationState $state,
		private Closure $pendingCheck,
		private Closure $successCheck,
		private Closure $errorCheck,
	) {}

	public function isPending(): bool
	{
		return ($this->pendingCheck)($this->state);
	}

	public function isSuccess(): bool
	{
		return ($this->successCheck)($this->state);
	}

	public function isErrorsLimitExceeded(): bool
	{
		return ($this->errorCheck)($this->state);
	}
}
