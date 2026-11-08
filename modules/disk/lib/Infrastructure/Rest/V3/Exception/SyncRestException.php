<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Exception;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * Root of every refusal the disk.sync.* contour publishes. It carries no code of its own:
 * catching the contour as a whole is what it exists for.
 *
 * Every refusal here is a verdict about the caller's request, not a fault of the portal, so the
 * whole family stays out of the error log.
 */
abstract class SyncRestException extends RestException implements SkipWriteToLogException
{
	protected const STATUS = \CRestServer::STATUS_WRONG_REQUEST;

	final public function getRegistryCode(): string
	{
		return $this->getErrorCode();
	}

	/**
	 * Published code of the refusal. Kept abstract so that no descendant can fall through to the
	 * inherited implementation, which derives the code from the FQCN and would publish the class
	 * path of the module as the contract of the method.
	 */
	abstract protected function getErrorCode(): string;
}
