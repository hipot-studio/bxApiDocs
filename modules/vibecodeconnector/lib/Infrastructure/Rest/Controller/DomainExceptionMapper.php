<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Controller;

use Bitrix\Main;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Internal\OrmSaveException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Exception\EmbeddingNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Exception\EmbeddingOperationRejectedException;

trait DomainExceptionMapper
{
	protected function mapDomainException(\Throwable $exception): ?\Throwable
	{
		if ($exception instanceof RestException)
		{
			return $exception;
		}

		if ($exception instanceof CatalogItemNotFoundException)
		{
			return new EntityNotFoundException($exception->catalogItemId);
		}

		if ($exception instanceof EmbeddingNotFoundException)
		{
			return new EntityNotFoundException($exception->embeddingId);
		}

		if ($exception instanceof EmbeddingOperationRejectedException)
		{
			return new RequestValidationException([
				new Main\Error($exception->getMessage(), $exception->getField()),
			]);
		}

		if ($exception instanceof Main\AccessDeniedException)
		{
			return new AccessDeniedException($exception);
		}

		if ($exception instanceof Main\ArgumentException)
		{
			return new RequestValidationException([
				new Main\Error(
					$exception->getMessage(),
					$exception->getParameter() !== '' ? (string)$exception->getParameter() : null,
				),
			]);
		}

		if ($exception instanceof Main\Repository\Exception\PersistenceException)
		{
			return new OrmSaveException($exception);
		}

		return null;
	}
}
