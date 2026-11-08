<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;

/**
 * The record a builder method addresses does not exist: the framework phrase naming its id, answered as 404
 * rather than the 400 of the framework class, with the builder failure code.
 */
class BuilderEntityNotFoundException extends EntityNotFoundException implements BuilderRefusal
{
	use FailureCodeAsRegistryCode;

	protected const STATUS = \CRestServer::STATUS_NOT_FOUND;

	public function getFailureCode(): FailureCode
	{
		return FailureCode::NotFound;
	}

	protected function getClassWithPhrase(): string
	{
		return EntityNotFoundException::class;
	}
}
