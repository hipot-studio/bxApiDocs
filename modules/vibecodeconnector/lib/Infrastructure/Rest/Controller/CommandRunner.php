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
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;

trait CommandRunner
{
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

			if ($previousException instanceof RestException)
			{
				throw $previousException;
			}

			if ($previousException instanceof CatalogItemNotFoundException)
			{
				throw new EntityNotFoundException($previousException->catalogItemId);
			}

			if ($previousException instanceof Main\AccessDeniedException)
			{
				throw new AccessDeniedException($previousException);
			}

			if ($previousException instanceof Main\ArgumentException)
			{
				throw new RequestValidationException([
					new Main\Error(
						$previousException->getMessage(),
						$previousException->getParameter() !== '' ? (string)$previousException->getParameter() : null,
					),
				]);
			}

			if ($previousException instanceof Main\Repository\Exception\PersistenceException)
			{
				throw new OrmSaveException($previousException);
			}

			throw new InternalException($exception);
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return $result;
	}
}
