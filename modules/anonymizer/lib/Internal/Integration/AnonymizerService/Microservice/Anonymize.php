<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Integration\AnonymizerService\Microservice;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

/**
 * Calls authenticated anonymizerproxy actions through microservice transport.
 * Keeps BaseSender request signing (BX_HASH/serializedParameters) and adds JWT signature headers.
 */
final class Anonymize extends BaseSender
{
	public function callAction(string $action, array $parameters): Result
	{
		$clientId = $this->proxyConfig->getProxyClientId();
		if ($clientId === null || $clientId === '')
		{
			$result = new Result();
			$result->addError(new Error('There is empty proxy registration data.'));

			return $result;
		}

		$parameters['clientId'] = $clientId;

		return $this->performRequest($action, $parameters);
	}

	protected function buildResult(HttpClient $httpClient, bool $requestResult): Result
	{
		$result = new Result();

		if (!$requestResult)
		{
			$errors = $httpClient->getError();
			foreach ($errors as $code => $message)
			{
				$result->addError(new Error((string)$message, (string)$code));
			}

			if (!$result->isSuccess())
			{
				return $result;
			}

			$result->addError(new Error('Proxy request failed', 'NETWORK'));

			return $result;
		}

		$status = $httpClient->getStatus();
		$rawResponse = $httpClient->getResult();
		$decoded = $this->decodeResponse($rawResponse);

		// Anonymize actions return 202 Accepted with {hash, request_id}.
		if ($status === 202)
		{
			$hash = is_array($decoded) ? ($decoded['hash'] ?? $decoded['request_id'] ?? null) : null;
			if (is_string($hash) && $hash !== '')
			{
				$result->setData(['hash' => $hash]);

				return $result;
			}

			$result->addError(new Error('Invalid proxy response JSON', 'MALFORMED_RESPONSE'));

			return $result;
		}

		if (is_array($decoded) && ($decoded['status'] ?? null) === 'error' && is_array($decoded['errors'] ?? null))
		{
			foreach ($decoded['errors'] as $error)
			{
				$result->addError(new Error(
					(string)($error['message'] ?? 'Unknown error'),
					(string)($error['code'] ?? $status),
					$error['customData'] ?? null,
				));
			}

			return $result;
		}

		$result->addError(new Error("Proxy returned {$status}", 'WRONG_SERVER_RESPONSE'));

		return $result;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function decodeResponse(string $rawResponse): ?array
	{
		if ($rawResponse === '')
		{
			return null;
		}

		try
		{
			$decoded = Json::decode($rawResponse);
		}
		catch (\Exception)
		{
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}
}
