<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Box;

use Bitrix\Disk\Document\OnlyOffice;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Http\Response;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;
use Closure;
use Psr\Log\NullLogger;
use Throwable;

final class RegistrationClient extends OnlyOffice\Cloud\BaseSender
{
	private const ERROR_CODES = [
		'tariff_restriction', 'demo_restriction', 'domain_verification',
		'vibeoffice_restore_required', 'vibeoffice_unknown_key', 'vibeoffice_confirm_incomplete',
		'vibeoffice_operation_in_progress', 'vibeoffice_platform_unavailable',
		'vibeoffice_http_not_allowed', 'vibeoffice_protocol_unsupported',
	];
	private const PLATFORM_CODES = [
		'unauthorized', 'rate_limited', 'internal', 'validation',
		'unknown_host', 'unknown_key', 'host_exists', 'no_webhook_url',
	];
	private const PROBE_REASONS = [
		'http_status', 'egress_blocked', 'timeout', 'dns', 'tls', 'connect', 'probe_unknown',
	];
	private string $operation = 'connect';

	public function __construct(string $serviceUrl, private readonly ?Closure $httpClientFactory = null)
	{
		parent::__construct($serviceUrl);
	}

	/** Connects a box using the current readable key, if present. */
	public function connect(string $languageId, ?string $keyId): Result
	{
		return $this->register('connect', $languageId, $keyId);
	}

	/** Reissues credentials after an administrator requests recovery. */
	public function restore(string $languageId): Result
	{
		return $this->register('restore', $languageId, null);
	}

	/** Confirms the issued key and probes the portal webhook. */
	public function confirm(string $clientId, string $keyId): Result
	{
		return $this->request('confirm', 'confirmVibeoffice', ['clientId' => $clientId, 'keyId' => $keyId]);
	}

	/** Issues the next API key without replacing the webhook secret. */
	public function rotate(string $clientId, string $keyId): Result
	{
		return $this->request('rotate', 'rotateVibeofficeKey', ['clientId' => $clientId, 'keyId' => $keyId]);
	}

	public function getHttpClientParameters(): array
	{
		$timeout = in_array($this->operation, ['connect', 'restore'], true) ? 45 : 40;

		return [
			'socketTimeout' => 10,
			'streamTimeout' => $timeout,
			'disableSslVerification' => false,
			'redirect' => false,
			'debugLevel' => 0,
			'curlLogFile' => '',
			'sendEvents' => false,
			'bodyLengthMax' => 65536,
		];
	}

	protected function buildHttpClient(): HttpClient
	{
		$parameters = $this->getHttpClientParameters();
		$httpClient = $this->httpClientFactory === null
			? new HttpClient($parameters)
			: ($this->httpClientFactory)($parameters)
		;
		$httpClient->setLogger(new NullLogger());
		$httpClient->setDebugLevel(0);
		$httpClient->shouldFetchBody(static function (Response $response): bool {
			$tempSecret = $response->getHeadersCollection()->get('X-Temp-Secret');
			if ($tempSecret)
			{
				(new OnlyOffice\Configuration())->storeTempSecretForDomainVerification($tempSecret);
			}

			return true;
		});

		return $httpClient;
	}

	protected function createAnswerForJsonResponse($queryResult, $response, $errors, $status): Result
	{
		if (!$queryResult || (int)$status !== 200 || !is_string($response) || $response === '')
		{
			return $this->unavailable();
		}
		try
		{
			$payload = Json::decode($response);
		}
		catch (Throwable)
		{
			return $this->unavailable();
		}
		if (!is_array($payload))
		{
			return $this->unavailable();
		}
		if (($payload['status'] ?? null) === 'error')
		{
			return $this->errors($payload['errors'] ?? null);
		}
		if (($payload['status'] ?? null) !== 'success' || !is_array($payload['data'] ?? null))
		{
			return $this->unavailable();
		}
		$data = $payload['data'];
		if (!array_key_exists('vibeoffice', $data))
		{
			return (new Result())->addError(new Error(
				'The document service must support Vibeoffice.', 'vibeoffice_service_outdated',
			));
		}
		$vibeoffice = $this->validateVibeoffice($data['vibeoffice']);
		if ($vibeoffice === null)
		{
			return $this->unavailable();
		}
		$validated = [];
		foreach (['client' => ['clientId', 'secretKey', 'serverHost'], 'documentServer' => ['host']] as $key => $fields)
		{
			if (!array_key_exists($key, $data))
			{
				continue;
			}
			if (!is_array($data[$key]))
			{
				return $this->unavailable();
			}
			foreach ($fields as $field)
			{
				if (!$this->isString($data[$key][$field] ?? null))
				{
					return $this->unavailable();
				}
				$validated[$key][$field] = $data[$key][$field];
			}
		}
		$validated['vibeoffice'] = $vibeoffice;

		return (new Result())->setData($validated);
	}

	private function register(string $mode, string $languageId, ?string $keyId): Result
	{
		return $this->request($mode, 'registerClient', [
			'domain' => UrlManager::getInstance()->getHostUrl(),
			'languageId' => $languageId,
			'type' => 'documents',
			'vibeoffice' => 1,
			'vibeofficeMode' => $mode,
			'keyId' => $keyId,
			'diskVersion' => ModuleManager::getVersion('disk'),
		]);
	}

	private function request(string $operation, string $action, array $parameters): Result
	{
		$this->operation = $operation;
		try
		{
			return $this->performRequest('documentproxy.Registration.' . $action, $parameters);
		}
		catch (Throwable)
		{
			return $this->unavailable();
		}
	}

	private function validateVibeoffice(mixed $value): ?array
	{
		if (!is_array($value) || !is_string($value['hostId'] ?? null)
			|| preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $value['hostId']) !== 1
			|| !is_string($value['keyId'] ?? null)
			|| preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $value['keyId']) !== 1
			|| !in_array($value['state'] ?? null, ['issued', 'confirmed'], true))
		{
			return null;
		}
		$fields = ['hostId', 'keyId', 'state'];
		if (array_key_exists('server', $value) || $this->operation !== 'confirm')
		{
			if (!$this->isString($value['server'] ?? null))
			{
				return null;
			}
			$uri = new Uri($value['server']);
			if ($uri->getScheme() !== 'https' || $uri->getHost() === '' || $uri->getUserInfo() !== ''
				|| $uri->getQuery() !== '' || $uri->getFragment() !== '')
			{
				return null;
			}
			array_unshift($fields, 'server');
		}
		$result = array_intersect_key($value, array_flip($fields));
		foreach (['apiSecret', 'webhookSecret'] as $key)
		{
			if (array_key_exists($key, $value))
			{
				if (!is_string($value[$key]) || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $value[$key]) !== 1)
				{
					return null;
				}
				$result[$key] = $value[$key];
			}
		}
		if (array_key_exists('probe', $value))
		{
			$probe = $this->validateProbe($value['probe']);
			if ($probe === null)
			{
				return null;
			}
			$result['probe'] = $probe;
		}
		if ($this->operation === 'confirm' && ($value['state'] !== 'confirmed' || !isset($result['probe'])))
		{
			return null;
		}

		return $result;
	}

	private function validateProbe(mixed $probe): ?array
	{
		if (!is_array($probe) || !is_bool($probe['ok'] ?? null) || !array_key_exists('reason', $probe)
			|| !array_key_exists('status', $probe) || !is_int($probe['durationMs'] ?? null) || $probe['durationMs'] < 0
			|| ($probe['status'] !== null
				&& (!is_int($probe['status']) || $probe['status'] < 100 || $probe['status'] > 599)))
		{
			return null;
		}
		$ok = $probe['ok'];
		$reason = $probe['reason'];
		if ($reason !== null && !in_array($reason, self::PROBE_REASONS, true))
		{
			$ok = false;
			$reason = 'probe_unknown';
		}
		elseif ($ok)
		{
			if ($reason !== null || $probe['status'] === null || $probe['status'] < 200 || $probe['status'] > 299)
			{
				return null;
			}
		}
		elseif ($reason === null
			|| ($reason === 'http_status' && ($probe['status'] === null
				|| ($probe['status'] >= 200 && $probe['status'] <= 299)))
			|| ($reason !== 'http_status' && $probe['status'] !== null))
		{
			return null;
		}

		return ['ok' => $ok, 'reason' => $reason, 'status' => $probe['status'], 'durationMs' => $probe['durationMs']];
	}

	private function errors(mixed $errors): Result
	{
		if (!is_array($errors) || $errors === [])
		{
			return $this->unavailable();
		}
		$result = new Result();
		foreach ($errors as $error)
		{
			if (!is_array($error) || !in_array($error['code'] ?? null, self::ERROR_CODES, true))
			{
				continue;
			}
			$customData = [];
			$data = is_array($error['customData'] ?? null) ? $error['customData'] : [];
			if (is_int($data['revokePending'] ?? null) && $data['revokePending'] >= 0)
			{
				$customData['revokePending'] = $data['revokePending'];
			}
			if (is_int($data['httpStatus'] ?? null) && $data['httpStatus'] >= 100 && $data['httpStatus'] <= 599)
			{
				$customData['httpStatus'] = $data['httpStatus'];
			}
			if (in_array($data['platformCode'] ?? null, self::PLATFORM_CODES, true))
			{
				$customData['platformCode'] = $data['platformCode'];
			}
			if (isset($data['supported']) && is_array($data['supported'])
				&& $data['supported'] === [1])
			{
				$customData['supported'] = [1];
			}
			$result->addError(new Error('The document service refused the operation.', $error['code'], $customData));
		}

		return $result->isSuccess() ? $this->unavailable() : $result;
	}

	private function isString(mixed $value): bool
	{
		return is_string($value) && $value !== '' && strlen($value) <= 2048
			&& preg_match('/[\x00-\x20\x7f]/', $value) !== 1;
	}

	private function unavailable(): Result
	{
		return (new Result())->addError(new Error(
			'The document service is unavailable. Try again later.', 'vibeoffice_documentproxy_unavailable',
		));
	}
}
