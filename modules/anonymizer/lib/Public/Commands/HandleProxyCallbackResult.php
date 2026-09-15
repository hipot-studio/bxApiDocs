<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Public\Commands;

use Bitrix\Anonymizer\Internal\Entities\Enum\RequestStatus;
use Bitrix\Anonymizer\Internal\Entities\Request;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

/**
 * Handles proxy callback result: validates payload, loads Request by hash, persists result or error,
 * notifies quest handler. Controller only passes payload data (from JsonPayload::getData() or decoded getRaw());
 * this command does all parsing and checks.
 */
final class HandleProxyCallbackResult
{
	/**
	 * Validates payload (hash, request exists, idempotent), then applies success or error.
	 * Call from controller after JWT check.
	 *
	 * @param array<string, mixed> $data Payload from callback (e.g. from JsonPayload::getData()),
	 * must contain hash, statusCode, body/error
	 * @return Result Success or errors (e.g. Missing hash, Request not found, Invalid JSON)
	 */
	public function handle(array $data): Result
	{
		$result = new Result();

		$hash = $data['hash'] ?? null;
		if ($hash === null || $hash === '')
		{
			$result->addError(new Error('Missing hash'));

			return $result;
		}

		$hash = (string)$hash;
		$requestEntity = Request::getByHash($hash);
		if ($requestEntity === null)
		{
			$result->addError(new Error('Request not found', 404));

			return $result;
		}

		if (
			$requestEntity->getStatus() === RequestStatus::Received
			|| $requestEntity->getStatus() === RequestStatus::Error
		)
		{
			return $result;
		}

		$statusCode = (int)($data['statusCode'] ?? 0);
		$bodyOrError = $data['body'] ?? $data['error'] ?? '';

		if ($statusCode >= 200 && $statusCode < 300)
		{
			$this->applySuccess($requestEntity, $bodyOrError);
		}
		else
		{
			$errorMessage = is_string($bodyOrError) ? $bodyOrError : (string)($data['error'] ?? 'Unknown error');
			$this->applyError($requestEntity, $errorMessage);
		}

		return $result;
	}

	/**
	 * Applies success response: normalizes body (string → decode JSON), checks for error in body,
	 * then setResult/onResponse or setError/onError.
	 */
	private function applySuccess(Request $request, mixed $body): void
	{
		$decoded = $this->normalizeBodyToArray($body);
		if ($decoded === null)
		{
			$request->setError('Invalid response JSON');
			$request->onError();

			return;
		}

		if (isset($decoded['error']) && $decoded['error'] !== '')
		{
			$request->setError((string)$decoded['error']);
			$request->onError();

			return;
		}

		$request->setResult($decoded);
		$request->onResponse();
	}

	/**
	 * Applies error response: saves error and notifies quest handler.
	 */
	private function applyError(Request $request, string $error): void
	{
		$request->setError($error);
		$request->onError();
	}

	/**
	 * @return array<string, mixed>|null Decoded array or null if body is invalid
	 */
	private function normalizeBodyToArray(mixed $body): ?array
	{
		if (is_array($body))
		{
			return $body;
		}

		if (!is_string($body) || $body === '')
		{
			return [];
		}

		try
		{
			$decoded = Json::decode($body);

			return is_array($decoded) ? $decoded : null;
		}
		catch (\Exception)
		{
			return null;
		}
	}
}
