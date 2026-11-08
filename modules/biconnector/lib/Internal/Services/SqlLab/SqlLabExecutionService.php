<?php

namespace Bitrix\BIConnector\Internal\Services\SqlLab;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\SqlExecutionContext;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * What every entry point of SQL Lab shares: validation of the query, the transport, the normalized
 * result with its truncation sign and the error codes. Access, quotas, the feature switch and the
 * channel of the call are decided by the entry point before it gets here.
 */
class SqlLabExecutionService
{
	private const DEFAULT_LIMIT = 1000;
	private const MAX_LIMIT = 10000;
	private const MAX_SQL_LENGTH = 50000;

	private const WIRE_CODES = [
		'SQLLAB_INVALID_QUERY',
		'SQLLAB_ACCESS_DENIED',
		'SQLLAB_RATE_LIMIT_EXCEEDED',
		'SQLLAB_TIMEOUT_ERROR',
		'SQLLAB_UNAVAILABLE',
	];

	/** The cURL code of a request that ran out of time on the way to the proxy. */
	private const TRANSPORT_TIMEOUT_ERROR_CODE = 28;

	private readonly SqlLabIntegratorTransport $transport;

	public function __construct(?SqlLabIntegratorTransport $transport = null)
	{
		$this->transport = $transport ?? new SqlLabIntegratorTransport();
	}

	public function isValidQuery(string $sql, ?int $limit): bool
	{
		if (trim($sql) === '' || mb_strlen($sql) > self::MAX_SQL_LENGTH)
		{
			return false;
		}

		return $limit === null || ($limit >= 1 && $limit <= self::MAX_LIMIT);
	}

	/**
	 * A null limit is left to the lower contour, which then reports the one it applied.
	 *
	 * The answer of the integrator travels in the data under `integratorResponse`, on failure too:
	 * an entry point that keeps its earlier presentation reads it there.
	 */
	public function execute(string $sql, ?int $limit, SqlExecutionContext $context): Result
	{
		$result = new Result();
		if (!$this->isValidQuery($sql, $limit))
		{
			return $this->fail($result, 'SQLLAB_INVALID_QUERY');
		}

		$response = $this->transport->execute(trim($sql), $limit, $context);
		$result->setData(['integratorResponse' => $response]);

		if ($response->hasErrors() || $response->getStatus() !== IntegratorResponse::STATUS_OK)
		{
			return $this->fail($result, $this->resolveErrorCode($response), $this->describeErrors($response));
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			return $this->fail($result, 'SQLLAB_UNAVAILABLE');
		}

		$columns = is_array($data['columns'] ?? null) ? $data['columns'] : [];
		$rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
		$rowCount = is_int($data['rowCount'] ?? null) ? $data['rowCount'] : count($rows);
		$appliedLimit = $limit ?? (is_int($data['limit'] ?? null) ? $data['limit'] : self::DEFAULT_LIMIT);
		if (array_key_exists('truncated', $data))
		{
			$truncated = is_bool($data['truncated']) ? $data['truncated'] : null;
		}
		else
		{
			$truncated = $rowCount < $appliedLimit ? false : null;
		}

		$result->setData([
			'columns' => $columns,
			'rows' => $rows,
			'rowCount' => $rowCount,
			'limit' => $appliedLimit,
			'truncated' => $truncated,
			'integratorResponse' => $response,
		]);

		return $result;
	}

	/**
	 * The integrator answers in its own terms: a stable wire code when the lower contour produced
	 * one, a transport status otherwise. Turning that into a code of SQL Lab belongs here, not in
	 * the integrator.
	 */
	private function resolveErrorCode(IntegratorResponse $response): string
	{
		foreach ($response->getErrors() as $error)
		{
			if (in_array($error->getCode(), self::WIRE_CODES, true))
			{
				return $error->getCode();
			}
		}

		if ($this->isTransportTimeout($response))
		{
			return 'SQLLAB_TIMEOUT_ERROR';
		}

		if ($response->getStatus() === IntegratorResponse::STATUS_NO_ACCESS)
		{
			return 'SQLLAB_ACCESS_DENIED';
		}

		return 'SQLLAB_UNAVAILABLE';
	}

	private function isTransportTimeout(IntegratorResponse $response): bool
	{
		$requestResult = $response->getRequestResult();
		if ($requestResult === null)
		{
			return false;
		}

		foreach ($requestResult->getErrors() as $error)
		{
			if ((int)$error->getCode() === self::TRANSPORT_TIMEOUT_ERROR_CODE)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The Superset diagnostics a model fixes its query by. Callers decide whether to show it.
	 */
	private function describeErrors(IntegratorResponse $response): string
	{
		$descriptions = [];
		foreach ($response->getErrors() as $error)
		{
			$customData = $error->getCustomData();
			$detail = is_array($customData) ? ($customData['detail'] ?? null) : null;
			$descriptions[] = is_string($detail) && $detail !== ''
				? $error->getMessage() . ': ' . $detail
				: $error->getMessage()
			;
		}

		return implode('; ', $descriptions);
	}

	private function fail(Result $result, string $code, string $detail = ''): Result
	{
		$result->addError(new Error(
			'SQL Lab request failed.',
			$code,
			$detail === '' ? null : ['detail' => $detail],
		));

		return $result;
	}
}
