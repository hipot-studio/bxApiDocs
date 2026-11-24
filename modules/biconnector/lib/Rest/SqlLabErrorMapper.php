<?php

namespace Bitrix\BIConnector\Rest;

use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\Main\Error;

final class SqlLabErrorMapper
{
	private const MAP = [
		'SQLLAB_INVALID_QUERY' => ['INVALID_QUERY', 'Invalid SQL query.', 400],
		'SQLLAB_ACCESS_DENIED' => ['ACCESS_DENIED', 'Access denied.', 403],
		'SQLLAB_RATE_LIMIT_EXCEEDED' => ['RATE_LIMIT_EXCEEDED', 'Too many requests.', 429],
		'SQLLAB_TIMEOUT_ERROR' => ['QUERY_TIMEOUT', 'Query timed out.', 504],
		'SQLLAB_UNAVAILABLE' => ['BI_UNAVAILABLE', 'BI service is unavailable.', 503],
	];

	public function mapResponse(IntegratorResponse $response): array
	{
		return $this->mapErrors($response->getErrors());
	}

	/** @param Error[] $errors */
	public function mapErrors(array $errors): array
	{
		$mapping = self::MAP['SQLLAB_UNAVAILABLE'];
		foreach ($errors as $error)
		{
			if ($error instanceof Error && isset(self::MAP[$error->getCode()]))
			{
				$mapping = self::MAP[$error->getCode()];
				if ($error->getCode() === 'SQLLAB_INVALID_QUERY')
				{
					// Only queryError carries the reason of the query: an earlier Superset put a text of
					// another error of the same answer into detail, and has no queryError at all.
					$customData = $error->getCustomData();
					$message = is_array($customData) ? ($customData['queryError'] ?? null) : null;
					if (
						is_string($message)
						&& trim($message) !== ''
						&& mb_strlen($message) <= 500
						&& strpbrk($message, "<\r\n") === false
					)
					{
						$mapping[1] = $message;
					}
				}

				break;
			}
		}

		return [
			'error' => $mapping[0],
			'error_description' => $mapping[1],
			'httpStatus' => $mapping[2],
		];
	}

	/**
	 * Serves both transports: CRestProvider::batch moves an answer carrying error and
	 * error_description into result_error of its command itself, keeping the outer batch answer at
	 * HTTP 200, so a batch item needs no envelope of its own (ADR p. 13).
	 *
	 * @param Error[] $errors
	 */
	public function forDirect(array $errors): array
	{
		$mapped = $this->mapErrors($errors);

		return [
			'httpStatus' => $mapped['httpStatus'],
			'body' => [
				'error' => $mapped['error'],
				'error_description' => $mapped['error_description'],
			],
		];
	}
}
