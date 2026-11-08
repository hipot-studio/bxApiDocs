<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

/**
 * Answers the FailureCode value in place of the registry code the framework builds from the class name:
 * that one would change with any move or rename of the class, while a client branches on the code.
 *
 * For classes implementing BuilderRefusal.
 */
trait FailureCodeAsRegistryCode
{
	public function getRegistryCode(): string
	{
		return $this->getFailureCode()->value;
	}
}
