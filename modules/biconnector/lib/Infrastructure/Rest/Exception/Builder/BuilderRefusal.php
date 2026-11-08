<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;

/**
 * Refusal of a builder method. Its code in the answer is the FailureCode value, the same one the method
 * catalog and the MCP entry point name, whichever exception class carries the refusal.
 */
interface BuilderRefusal
{
	public function getFailureCode(): FailureCode;
}
