<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Controller;

use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Internal\InternalException;
use Bitrix\Rest\V3\Exception\Internal\OrmSaveException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

trait CallableRunner
{
	use DomainExceptionMapper;

	/**
	 * @throws AccessDeniedException
	 * @throws EntityNotFoundException
	 * @throws InternalException
	 * @throws OrmSaveException
	 * @throws RequestValidationException
	 * @throws RestException
	 */
	protected function runCallable(callable $operation): mixed
	{
		try
		{
			return $operation();
		}
		catch (\Throwable $exception)
		{
			throw $this->mapDomainException($exception) ?? new InternalException($exception);
		}
	}
}
