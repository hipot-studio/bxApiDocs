<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\Rest\V3\Exception\AccessDeniedException;

/**
 * The framework refusal of access, answered with the builder failure code.
 */
class BuilderAccessDeniedException extends AccessDeniedException implements BuilderRefusal
{
	use FailureCodeAsRegistryCode;

	public function getFailureCode(): FailureCode
	{
		return FailureCode::AccessDenied;
	}

	protected function getClassWithPhrase(): string
	{
		return AccessDeniedException::class;
	}
}
