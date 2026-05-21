<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action;

interface AIOperationStateChecker
{
	public function isPending(): bool;
	public function isSuccess(): bool;
	public function isErrorsLimitExceeded(): bool;
}
