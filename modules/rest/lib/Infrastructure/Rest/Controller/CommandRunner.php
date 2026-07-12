<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Controller;

use Bitrix\Main;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Result;
use Bitrix\Rest\Infrastructure;
use Bitrix\Rest\Internal;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Internal\InternalException;
use Bitrix\Rest\V3\Exception\Internal\OrmSaveException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

trait CommandRunner
{
	/**
	 * @throws RequestValidationException
	 * @throws AccessDeniedException
	 * @throws OrmSaveException
	 * @throws RestException
	 */
	protected function runCommand(CommandInterface $command): Result
	{
		$result = new Result();

		try
		{
			$result = $command->run();
		}
		catch (Main\Command\Exception\CommandValidationException $exception)
		{
			$result->addErrors($exception->getValidationErrors());
		}
		catch (CommandException $exception)
		{
			$previousException = $exception->getPrevious();

			if ($previousException instanceof RestException)
			{
				throw $previousException;
			}

			if ($previousException instanceof Internal\Exception\Application\ApplicationNotFoundException)
			{
				throw new Infrastructure\Rest\Exception\ApplicationNotFoundException($previousException->clientId);
			}
			if ($previousException instanceof Internal\Exception\Application\ApplicationNotInstalledException)
			{
				throw new Infrastructure\Rest\Exception\ApplicationNotInstalledException($previousException);
			}

			if ($previousException instanceof Internal\Exception\IncomingWebhook\IncomingWebhookNotFoundException)
			{
				throw new Infrastructure\Rest\Exception\IncomingWebhookNotFoundException($previousException);
			}

			if ($previousException instanceof Main\AccessDeniedException)
			{
				throw new AccessDeniedException($previousException);
			}

			if ($previousException instanceof Main\Repository\Exception\PersistenceException)
			{
				throw new OrmSaveException($previousException);
			}

			if ($previousException instanceof Internal\Exception\ArgumentException)
			{
				throw new RequestValidationException([
					new Main\Error($previousException->getMessage(), $previousException->getParameter()),
				]);
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
