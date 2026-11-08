<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Attribute;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;

/**
 * Refusal codes a builder REST action can answer with; the method catalog reads them from here.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class FailureCodes
{
	/** @var FailureCode[] */
	public readonly array $codes;

	public function __construct(FailureCode ...$codes)
	{
		$this->codes = $codes;
	}
}
