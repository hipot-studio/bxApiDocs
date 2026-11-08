<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Controller;

use Bitrix\Main;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Internal\InternalException;
use Bitrix\Rest\V3\Exception\Internal\OrmSaveException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

trait CommandRunner
{
	use DomainExceptionMapper;

	/**
	 * @throws AccessDeniedException
	 * @throws EntityNotFoundException
	 * @throws OrmSaveException
	 * @throws RequestValidationException
	 * @throws RestException
	 */
	protected function runCommand(CommandInterface $command): Result
	{
		try
		{
			$result = $command->run();
		}
		catch (Main\Command\Exception\CommandValidationException $exception)
		{
			throw new RequestValidationException($exception->getValidationErrors());
		}
		catch (CommandException $exception)
		{
			$previousException = $exception->getPrevious();
			$mappedException = $previousException === null
				? null
				: $this->mapDomainException($previousException);

			throw $mappedException ?? new InternalException($exception);
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return $result;
	}
}
