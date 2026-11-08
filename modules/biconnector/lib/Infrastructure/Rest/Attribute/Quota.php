<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Attribute;

use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;

/**
 * Quota class a builder REST action is counted against. An action without it is not run.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Quota
{
	public function __construct(public readonly BuilderQuotaClass $class)
	{
	}
}
