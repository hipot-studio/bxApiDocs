<?php

namespace Bitrix\BIConnector\Internal\PortalHealthcheck;

use Bitrix\BIConnector\Integration\Superset\Integrator\Sender;
use Bitrix\Main\Result;

final class ProxyClient
{
	private const START_ACTION = '/portal/healthcheck/start';
	private const START_STATUSES = ['RUNNING', 'PORTAL_UNAVAILABLE', 'NODES_CHECK_REJECTED'];
	private const PROXY_ERRORS = [
		'ERR_NETWORK', 'ERR_PRIVATE_ADDRESS', 'ERR_REDIRECT', 'ERR_HTTP_STATUS', 'ERR_INVALID_RESPONSE',
	];
	// HttpClient rejects these requests before opening a connection.
	private const UNSENT_TRANSPORT_ERRORS = ['URI_SCHEME', 'URI_HOST', 'URI_PUNICODE', 'PRIVATE_IP'];
	// Connection stage failures of the socket and cURL handlers: DNS, connect, TLS handshake, proxy CONNECT.
	private const CONNECTION_FAILURE_PATTERN = '/^(?:\[\d+\] |Socket connection error\.'
		. '|Error establishing an SSL connection\.|Error receiving the CONNECT response from the proxy'
		. '|Could not resolve |Resolving timed out|Failed to connect |Couldn\'t connect |Connection timed out after '
		. '|SSL certificate problem|SSL: |SSL connect error|OpenSSL SSL_connect)/';

	public function __construct(private readonly Sender $sender = new Sender())
	{
	}

	public function start(#[\SensitiveParameter] string $healthToken): ProxyStartReply
	{
		$result = $this->sender->performSecureRequest(self::START_ACTION, ['healthToken' => $healthToken]);
		$envelope = $result->getData();
		$httpStatus = is_int($envelope['status'] ?? null) ? $envelope['status'] : 0;
		$data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
		$errorCode = $this->errorCode($httpStatus, (string)($result->getError()?->getCode() ?? ''));

		if ($httpStatus === 0)
		{
			return new ProxyStartReply(
				0,
				errorCode: 'PROXY_NETWORK_ERROR',
				requestNotSent: $this->isRequestNotSent($result),
			);
		}
		if ($httpStatus === 426 && $result->getError()?->getCode() === Sender::ERROR_HTTPS_REQUIRED)
		{
			return new ProxyStartReply(426, errorCode: 'HTTPS_REQUIRED', requestNotSent: true);
		}
		if ($httpStatus === 429)
		{
			return new ProxyStartReply(
				429,
				errorCode: $errorCode,
				nextAllowedAt: $this->utcDate($data['nextAllowedAt'] ?? null),
				retryAfter: $this->positiveInteger($data['retryAfter'] ?? null),
			);
		}
		if ($httpStatus !== 200 && $httpStatus !== 503)
		{
			return new ProxyStartReply($httpStatus, errorCode: $errorCode);
		}

		$diagnosticId = $this->diagnosticId($data['diagnosticId'] ?? null);
		$proxy = $this->proxy($data['proxy'] ?? null);
		$nextAllowedAt = $this->utcDate($data['nextAllowedAt'] ?? null);
		if ($httpStatus === 503)
		{
			return new ProxyStartReply(
				503,
				errorCode: $errorCode,
				diagnosticId: $diagnosticId,
				proxy: $proxy,
				nextAllowedAt: $nextAllowedAt,
			);
		}

		$status = $data['status'] ?? null;
		$nodesCheck = $status === 'NODES_CHECK_REJECTED' ? $this->nodesCheck($data['nodesCheck'] ?? null) : null;
		$resultDeadline = $status === 'RUNNING' ? $this->utcDate($data['resultDeadline'] ?? null) : null;
		if (!in_array($status, self::START_STATUSES, true)
			|| $diagnosticId === null
			|| $proxy === null
			|| $nextAllowedAt === null
			|| ($status === 'RUNNING' && $resultDeadline === null)
			|| ($status === 'NODES_CHECK_REJECTED' && $nodesCheck === null))
		{
			return new ProxyStartReply(200, errorCode: 'HEALTHCHECK_PROTOCOL_ERROR');
		}

		return new ProxyStartReply(
			200,
			status: $status,
			diagnosticId: $diagnosticId,
			proxy: $proxy,
			nodesCheck: $nodesCheck,
			nextAllowedAt: $nextAllowedAt,
			resultDeadline: $resultDeadline,
		);
	}

	private function isRequestNotSent(Result $result): bool
	{
		foreach ($result->getErrors() as $error)
		{
			$code = (string)$error->getCode();
			if (in_array($code, self::UNSENT_TRANSPORT_ERRORS, true))
			{
				return true;
			}
			if ($code === 'NETWORK' && preg_match(self::CONNECTION_FAILURE_PATTERN, $error->getMessage()) === 1)
			{
				return true;
			}
		}

		return false;
	}

	private function errorCode(int $httpStatus, string $serviceCode): ?string
	{
		return match ($httpStatus)
		{
			200 => null,
			400 => in_array($serviceCode, [
				'HEALTHCHECK_TOKEN_INVALID', 'PORTAL_URL_INVALID', 'PORTAL_HOST_MISMATCH',
			], true) ? $serviceCode : 'PROXY_ERROR',
			403 => 'LICENSE_INVALID',
			404 => 'HEALTHCHECK_NOT_SUPPORTED',
			426 => 'HTTPS_REQUIRED',
			429 => in_array($serviceCode, [
				'HEALTHCHECK_RATE_LIMITED',
			], true) ? $serviceCode : 'PROXY_ERROR',
			503 => in_array($serviceCode, [
				'HEALTHCHECK_NOT_CONFIGURED', 'HEALTHCHECK_QUEUE_FULL', 'HEALTHCHECK_CONTROLLER_UNAVAILABLE',
			], true) ? $serviceCode : 'PROXY_ERROR',
			default => 'PROXY_ERROR',
		};
	}

	private function diagnosticId(mixed $id): ?string
	{
		return is_string($id)
			&& preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id) === 1
				? strtolower($id)
				: null;
	}

	private function utcDate(mixed $value): ?\DateTimeImmutable
	{
		if (!is_string($value)
			|| preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)$/D', $value) !== 1)
		{
			return null;
		}
		try
		{
			$date = new \DateTimeImmutable($value);
			$errors = \DateTimeImmutable::getLastErrors();
			if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
			{
				return null;
			}

			return $date->setTimezone(new \DateTimeZone('UTC'));
		}
		catch (\Exception $exception)
		{
			return null;
		}
	}

	private function positiveInteger(mixed $value): ?int
	{
		return is_int($value) && $value >= 0 ? $value : null;
	}

	private function proxy(mixed $value): ?array
	{
		if (!is_array($value) || !is_string($value['address'] ?? null)
			|| $value['address'] === '' || strlen($value['address']) > 255
			|| !in_array($value['status'] ?? null, ['SUCCESS', 'FAILED'], true)
			|| !is_int($value['httpCode'] ?? null)
			|| !array_key_exists('latencyMs', $value)
			|| !is_array($value['egressAddresses'] ?? null)
			|| !array_is_list($value['egressAddresses'])
			|| !is_array($value['errors'] ?? null)
			|| !array_is_list($value['errors']))
		{
			return null;
		}
		$httpCode = $value['httpCode'];
		if ($httpCode !== 0 && ($httpCode < 100 || $httpCode > 599))
		{
			return null;
		}
		if ($value['status'] === 'SUCCESS' && $httpCode !== 200)
		{
			return null;
		}
		$latency = $value['latencyMs'] ?? null;
		if ($latency !== null && (!is_int($latency) || $latency < 0))
		{
			return null;
		}
		foreach ($value['egressAddresses'] as $address)
		{
			if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false)
			{
				return null;
			}
		}
		$errors = [];
		foreach ($value['errors'] as $error)
		{
			if (!is_array($error) || !is_string($error['code'] ?? null))
			{
				return null;
			}
			$message = $error['message'] ?? null;
			$errors[] = [
				'code' => in_array($error['code'], self::PROXY_ERRORS, true)
					? $error['code'] : 'ERR_UNKNOWN',
				'message' => is_string($message) ? mb_strcut($message, 0, 1024, 'UTF-8') : '',
			];
		}
		if (count($errors) !== ($value['status'] === 'SUCCESS' ? 0 : 1))
		{
			return null;
		}

		return [
			'address' => $value['address'],
			'egressAddresses' => $value['egressAddresses'],
			'status' => $value['status'],
			'httpCode' => $httpCode,
			'latencyMs' => $latency,
			'errors' => $errors,
		];
	}

	private function nodesCheck(mixed $value): ?array
	{
		if (!is_array($value) || ($value['status'] ?? null) !== 'REJECTED'
			|| !in_array($value['code'] ?? null, [
				'TARGET_NOT_HTTPS', 'TARGET_NOT_ALLOWED', 'CONTROLLER_REJECTED',
			], true))
		{
			return null;
		}

		return ['status' => 'REJECTED', 'code' => $value['code']];
	}
}
