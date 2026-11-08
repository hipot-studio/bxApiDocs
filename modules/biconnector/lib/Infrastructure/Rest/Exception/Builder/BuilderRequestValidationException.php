<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

/**
 * The framework refusal of request arguments, answered with the builder failure code. The text of the
 * operation goes out as an item of the validation list.
 */
class BuilderRequestValidationException extends RequestValidationException implements BuilderRefusal
{
	use FailureCodeAsRegistryCode;

	public function getFailureCode(): FailureCode
	{
		return FailureCode::InvalidArgument;
	}
}
