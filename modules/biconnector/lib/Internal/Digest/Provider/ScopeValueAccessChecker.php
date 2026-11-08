<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Provider;

use Bitrix\BIConnector\Public\Provider\ScopeAccessibleValueProvider;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\Parameter;

/**
 * Scope values a recipient may see, asked in the two shapes the readiness check
 * needs: does the recipient have any value at all, and is the configured one
 * among them.
 * Left open for extension so unit tests can replace it with a stub: the
 * underlying provider is final.
 */
class ScopeValueAccessChecker
{
	public function __construct(private readonly ScopeAccessibleValueProvider $valueProvider)
	{
	}

	public function isValueAccessible(Parameter $parameter, int $recipientId, int $valueId): bool
	{
		return $this->valueProvider->isAccessible($parameter, $recipientId, $valueId);
	}

	/**
	 * One row is enough to tell an empty list from a non-empty one.
	 */
	public function hasAnyAccessibleValue(Parameter $parameter, int $recipientId): bool
	{
		return $this->valueProvider->getList($parameter, $recipientId, null, 1) !== [];
	}
}
