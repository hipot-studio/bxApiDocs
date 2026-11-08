<?php

namespace Bitrix\BIConnector\Internal\PortalHealthcheck;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ResultValidator
{
	public const MAX_BODY_BYTES = 64 * 1024;
	public const MAX_NODES = 16;

	private const PROXY_ERRORS = [
		'ERR_NETWORK', 'ERR_PRIVATE_ADDRESS', 'ERR_REDIRECT', 'ERR_HTTP_STATUS', 'ERR_INVALID_RESPONSE',
	];
	private const NODE_ERRORS = [
		'ERR_SSH_UNAVAILABLE', 'ERR_SCRIPT_UNAVAILABLE', 'ERR_SCRIPT_TIMEOUT', 'ERR_INVALID_OUTPUT',
		'ERR_DNS_RESOLUTION', 'ERR_CONNECTION_REFUSED', 'ERR_TIMEOUT', 'ERR_TLS', 'ERR_TLS_CERTIFICATE',
		'ERR_CURL_FAILED', 'ERR_HTTP_STATUS', 'ERR_UNKNOWN',
	];

	public function validateCallback(array $body): Result
	{
		$result = new Result();
		try
		{
			if (!$this->fitsBodyLimit($body))
			{
				throw new \InvalidArgumentException();
			}
			$token = $this->requiredString($body, 'token', 128);
			if (preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $token) !== 1)
			{
				throw new \InvalidArgumentException();
			}
			$diagnosticId = $this->requiredString($body, 'diagnosticId', 36);
			if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $diagnosticId) !== 1)
			{
				throw new \InvalidArgumentException();
			}
			if (($body['status'] ?? null) !== 'COMPLETED')
			{
				throw new \InvalidArgumentException();
			}
			$checkedAt = $this->utcTime($this->requiredString($body, 'checkedAt', 35));
			$proxy = $this->check($body['proxy'] ?? null, false);
			$nodes = $body['nodes'] ?? null;
			if (!is_array($nodes) || !array_is_list($nodes) || count($nodes) > self::MAX_NODES)
			{
				throw new \InvalidArgumentException();
			}
			$normalizedNodes = [];
			foreach ($nodes as $node)
			{
				$normalizedNodes[] = $this->check($node, true);
			}
			$result->setData([
				'token' => $token,
				'diagnosticId' => strtolower($diagnosticId),
				'status' => 'COMPLETED',
				'checkedAt' => $checkedAt,
				'proxy' => $proxy,
				'nodes' => $normalizedNodes,
			]);
		}
		catch (\InvalidArgumentException $exception)
		{
			$result->addError(new Error('Invalid portal healthcheck callback', 'BAD_REQUEST'));
		}

		return $result;
	}

	private function fitsBodyLimit(array $body): bool
	{
		try
		{
			return strlen(json_encode($body, JSON_THROW_ON_ERROR)) <= self::MAX_BODY_BYTES;
		}
		catch (\JsonException $exception)
		{
			return false;
		}
	}

	private function check(mixed $check, bool $isNode): array
	{
		if (!is_array($check) || array_is_list($check))
		{
			throw new \InvalidArgumentException();
		}
		$status = $this->requiredString($check, 'status', 16);
		if (!in_array($status, ['SUCCESS', 'FAILED'], true))
		{
			throw new \InvalidArgumentException();
		}
		$httpCode = $this->requiredInteger($check, 'httpCode');
		if ($httpCode !== 0 && ($httpCode < 100 || $httpCode > 599))
		{
			throw new \InvalidArgumentException();
		}
		if ($status === 'SUCCESS' && $httpCode !== 200)
		{
			throw new \InvalidArgumentException();
		}
		if (!array_key_exists('latencyMs', $check))
		{
			throw new \InvalidArgumentException();
		}
		$latency = $check['latencyMs'];
		if ($latency !== null && (!is_int($latency) || $latency < 0))
		{
			throw new \InvalidArgumentException();
		}
		$errors = $this->errors($check['errors'] ?? null, $status, $isNode);
		$normalized = [
			'status' => $status,
			'httpCode' => $httpCode,
			'latencyMs' => $latency,
			'errors' => $errors,
		];
		if ($isNode)
		{
			$node = $this->requiredString($check, 'node', 128);
			if (preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $node) !== 1)
			{
				throw new \InvalidArgumentException();
			}
			$remoteIp = $this->requiredString($check, 'remoteIp', 45, true);
			if ($remoteIp !== '' && filter_var($remoteIp, FILTER_VALIDATE_IP) === false)
			{
				throw new \InvalidArgumentException();
			}
			$normalized['node'] = $node;
			if (array_key_exists('publicHost', $check))
			{
				$publicHost = $this->requiredString($check, 'publicHost', 254);
				if (preg_match(
					'/^(?=.{1,253}\.?$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?'
					. '(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+\.?$/D',
					$publicHost,
				) !== 1)
				{
					throw new \InvalidArgumentException();
				}
				$normalized['publicHost'] = $publicHost;
			}
			$normalized['remoteIp'] = $remoteIp;
		}
		else
		{
			$normalized['address'] = $this->requiredString($check, 'address', 255);
			$addresses = $check['egressAddresses'] ?? null;
			if (!is_array($addresses) || !array_is_list($addresses))
			{
				throw new \InvalidArgumentException();
			}
			foreach ($addresses as $address)
			{
				if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false)
				{
					throw new \InvalidArgumentException();
				}
			}
			$normalized['egressAddresses'] = $addresses;
		}

		return $normalized;
	}

	private function errors(mixed $errors, string $status, bool $isNode): array
	{
		if (!is_array($errors) || !array_is_list($errors)
			|| count($errors) !== ($status === 'SUCCESS' ? 0 : 1))
		{
			throw new \InvalidArgumentException();
		}
		if ($errors === [])
		{
			return [];
		}
		$error = $errors[0];
		if (!is_array($error) || array_is_list($error))
		{
			throw new \InvalidArgumentException();
		}
		$code = $this->requiredString($error, 'code', 128);
		$message = $this->requiredString($error, 'message', 1024, true);
		$known = $isNode ? self::NODE_ERRORS : self::PROXY_ERRORS;
		$normalized = [
			'code' => in_array($code, $known, true) ? $code : 'ERR_UNKNOWN',
			'message' => $message,
		];
		if ($isNode && array_key_exists('details', $error))
		{
			$details = $error['details'];
			if (!is_array($details) || array_is_list($details)
				|| array_diff(array_keys($details), ['curlExitCode', 'scriptExitCode']) !== [])
			{
				throw new \InvalidArgumentException();
			}
			foreach ($details as $value)
			{
				if (!is_int($value))
				{
					throw new \InvalidArgumentException();
				}
			}
			$normalized['details'] = $details;
		}

		return [$normalized];
	}

	private function utcTime(string $value): string
	{
		if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)$/D', $value) !== 1)
		{
			throw new \InvalidArgumentException();
		}
		try
		{
			$date = new \DateTimeImmutable($value);
			$errors = \DateTimeImmutable::getLastErrors();
			if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
			{
				throw new \InvalidArgumentException();
			}
		}
		catch (\Exception $exception)
		{
			throw new \InvalidArgumentException();
		}

		return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}

	private function requiredString(array $data, string $key, int $maxBytes, bool $allowEmpty = false): string
	{
		$value = $data[$key] ?? null;
		if (!is_string($value) || strlen($value) > $maxBytes || (!$allowEmpty && $value === ''))
		{
			throw new \InvalidArgumentException();
		}

		return $value;
	}

	private function requiredInteger(array $data, string $key): int
	{
		$value = $data[$key] ?? null;
		if (!is_int($value))
		{
			throw new \InvalidArgumentException();
		}

		return $value;
	}
}
