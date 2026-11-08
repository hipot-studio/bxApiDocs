<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

use Bitrix\Main;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Result;
use Bitrix\Rest\Exceptions\ArgumentException as RestArgumentException;
use Bitrix\Rest\HandlerHelper;
use Bitrix\Rest\PlacementTable;
use Bitrix\Rest\RestException;
use Bitrix\Vibecodeconnector\Internal\Exception\EmbeddingOperationRejectedException;

final class EmbeddingCommandRunner
{
	private const FIELD_BY_ERROR_CODE = [
		PlacementTable::ERROR_PLACEMENT_NOT_FOUND => 'placement',
		PlacementTable::ERROR_PLACEMENT_MAX_COUNT => 'placement',
		PlacementTable::ERROR_PLACEMENT_USER_MODE => 'placement',
		HandlerHelper::ERROR_WRONG_HANDLER_URL => 'handler',
		HandlerHelper::ERROR_UNSUPPORTED_PROTOCOL => 'handler',
	];

	public function run(CommandInterface $command): Result
	{
		try
		{
			$result = $command->run();
		}
		catch (CommandException $exception)
		{
			throw $this->mapCommandFailure($exception);
		}

		if (!$result->isSuccess())
		{
			$error = $result->getErrors()[0] ?? null;
			$errorCode = $error === null ? null : (string)$error->getCode();

			throw new EmbeddingOperationRejectedException(
				$error === null ? 'Embedding operation was rejected' : $error->getMessage(),
				$errorCode,
				$this->getFieldByErrorCode($errorCode),
			);
		}

		return $result;
	}

	private function mapCommandFailure(CommandException $exception): \Throwable
	{
		$previousException = $exception->getPrevious();

		if ($previousException instanceof RestArgumentException)
		{
			return new EmbeddingOperationRejectedException(
				$previousException->getMessage(),
				(string)$previousException->getErrorCode(),
				$this->getRestArgumentName($previousException),
				$previousException,
			);
		}

		if ($previousException instanceof RestException)
		{
			$errorCode = (string)$previousException->getErrorCode();

			return new EmbeddingOperationRejectedException(
				$previousException->getMessage(),
				$errorCode,
				$this->getFieldByErrorCode($errorCode),
				$previousException,
			);
		}

		if ($previousException instanceof Main\ArgumentException)
		{
			return new EmbeddingOperationRejectedException(
				$previousException->getMessage(),
				field: $previousException->getParameter() !== '' ? (string)$previousException->getParameter() : null,
				previous: $previousException,
			);
		}

		return $previousException ?? $exception;
	}

	private function getFieldByErrorCode(?string $errorCode): ?string
	{
		return $errorCode === null ? null : (self::FIELD_BY_ERROR_CODE[$errorCode] ?? null);
	}

	private function getRestArgumentName(RestArgumentException $exception): ?string
	{
		$parameter = $exception->getParameter();
		if ($parameter !== '')
		{
			return $parameter;
		}

		$additional = $exception->getAdditional()['argument'] ?? null;

		return is_string($additional) && $additional !== '' ? $additional : null;
	}
}
