<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\DetailPageLayout;

use Bitrix\Crm\V2\Infrastructure\Rest\Exception\DetailPageLayout\DetailPageLayoutClientException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\DetailPageLayout\DetailPageLayoutException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\DetailPageLayout\DetailPageLayoutStorageException;
use Bitrix\Main\Result;

final class LayoutErrorMapper
{
	public function map(string|int $code): DetailPageLayoutException
	{
		$parameters = match ($code) {
			'INVALID_LAYOUT_ID' => [
				'INVALID_LAYOUT_ID',
				'Invalid form layout ID.',
				'400 Bad Request',
			],
			'UNSUPPORTED_CONTEXT' => [
				'UNSUPPORTED_CONTEXT',
				'This form layout context is not supported.',
				'400 Bad Request',
			],
			'INVALID_LAYOUT_CONFIGURATION' => [
				'INVALID_LAYOUT_CONFIGURATION',
				'Invalid form layout configuration.',
				'400 Bad Request',
			],
			'INVALID_LAYOUT_AUDIENCE' => [
				'INVALID_LAYOUT_AUDIENCE',
				'Invalid form layout audience.',
				'400 Bad Request',
			],
			'PERMISSION_DENIED' => [
				'PERMISSION_DENIED',
				'Insufficient permissions to work with this form layout.',
				'403 Forbidden',
			],
			'LAYOUT_NOT_FOUND' => [
				'LAYOUT_NOT_FOUND',
				'The form layout was not found.',
				'404 Not Found',
			],
			'STORAGE_FAILED' => [
				'STORAGE_FAILED',
				'Could not complete the operation on the form layout.',
				'500 Internal Server Error',
			],
			'CONSISTENCY_FAILURE' => [
				'CONSISTENCY_FAILURE',
				'Form layout changes were partially applied.',
				'500 Internal Server Error',
			],
			default => throw new \LogicException('Unexpected public layout error code: ' . $code),
		};

		return $parameters[2] === '500 Internal Server Error'
			? new DetailPageLayoutStorageException(...$parameters)
			: new DetailPageLayoutClientException(...$parameters)
		;
	}

	public function throwIfFailed(Result $result): void
	{
		if ($result->isSuccess())
		{
			return;
		}

		$error = $result->getError();
		if ($error === null)
		{
			throw new \LogicException('A failed public layout result must contain an error.');
		}

		throw $this->map($error->getCode());
	}
}
