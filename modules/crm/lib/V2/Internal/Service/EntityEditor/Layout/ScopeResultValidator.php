<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout;

use Bitrix\Crm\V2\Internal\Integration\UI\EntityEditorScopeGateway;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class ScopeResultValidator
{
	public function __construct(private readonly EntityEditorScopeGateway $scopeGateway)
	{
	}

	public function ensureMatchesMode(Result $result, Context $context): Result
	{
		if (!$result->isSuccess())
		{
			return $result->getError()?->getCode() === ErrorCode::ACCESS_DENIED
				? FailureResultFactory::layoutNotFound()
				: $result;
		}

		$scope = $result->getData()['scope'] ?? null;
		if (!$scope instanceof Scope || !$this->scopeGateway->belongsToMode($scope, $context))
		{
			return FailureResultFactory::layoutNotFound();
		}

		return $result;
	}
}
