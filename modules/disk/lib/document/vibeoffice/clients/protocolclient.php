<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Clients;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Disk\Document\Vibeoffice\Logging\LoggerProvider;
use Psr\Log\LoggerInterface;

/**
 * Default ProtocolClient implementation over Bitrix\Main\Web\HttpClient.
 *
 * Builds the url/path/body, percent-encodes docKey/userId, signs each request via
 * Signer (4 X-VO-* headers) and maps platform status codes into a Result.
 *
 * The api-secret used by the Signer never leaves the backend (ADR invariant): it
 * is only used to compute the signature here. All calls are cross-service and run
 * outside any DB transaction.
 */
final class ProtocolClient implements ProtocolClientInterface
{
	private const OPEN_CAPABILITIES = ['X-VO-Accept-Open' => 'save-refused-v1, filetype-not-editable-v1'];
	private const LOGGABLE_PLATFORM_CODES = [
		'save_refused', 'doc_full', 'file_too_large', 'filetype_not_editable',
		'unsupported_filetype', 'no_capacity', 'saving_in_progress',
		'session_closing', 'open_in_progress', 'failover_in_progress', 'open_session_closed',
	];

	private string $baseUrl;
	private Signer $signer;
	/** @var (\Closure(): HttpClient)|null */
	private ?\Closure $httpClientFactory;
	private ProtocolCallContext $callContext;
	private LoggerInterface $logger;
	/**
	 * @param (\Closure(): HttpClient)|null $httpClientFactory test seam; without it
	 *        the client is built by {@see buildHttpClient()} with the production timeouts.
	 */
	public function __construct(
		string $baseUrl,
		Signer $signer,
		?AvatarSourceResolver $avatarSourceResolver = null,
		?\Closure $httpClientFactory = null,
		?ProtocolCallContext $callContext = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->baseUrl = rtrim($baseUrl, '/');
		$this->signer = $signer;
		$this->httpClientFactory = $httpClientFactory;
		$this->callContext = $callContext ?? new ProtocolCallContext();
		$this->logger = $logger ?? LoggerProvider::get('disk.vibeoffice.protocol');
	}

	public function open(string $docKey, array $body, ?float $deadline = null): Result
	{
		unset($body['user']['image']);

		return $this->send(
			HttpClient::HTTP_POST,
			self::buildOpenPath($docKey),
			$body,
			$deadline,
			self::OPEN_CAPABILITIES,
			'/v1/documents/{docKey}/open',
			'open',
		);
	}

	/**
	 * Only an HTTPS endpoint vouches for the answer of a send, and only while the certificate check of the
	 * HTTP client is left on: the global http_client_options may switch the peer and name verification off
	 * for every client, and such a transport keeps its success but proves no outcome, as HTTP does.
	 * Redirects are never followed by {@see self::send()}.
	 */
	public function isTrustedTransport(): bool
	{
		$scheme = parse_url($this->baseUrl, PHP_URL_SCHEME);
		$host = parse_url($this->baseUrl, PHP_URL_HOST);
		$defaults = Configuration::getValue('http_client_options');
		$verificationDisabled = is_array($defaults) && ($defaults['disableSslVerification'] ?? null) === true;

		return !$verificationDisabled
			&& is_string($scheme)
			&& strtolower($scheme) === 'https'
			&& is_string($host)
			&& $host !== ''
		;
	}

	/**
	 * Fingerprint of the open request exactly as {@see self::open()} sends it, with the egress
	 * authorization values masked. Null when the body cannot be masked unambiguously.
	 */
	public function fingerprintOpen(string $docKey, array $body, string $sourceBinding): ?string
	{
		unset($body['user']['image']);
		$rawBody = OpenFingerprint::encodeBody($body);
		$maskedBody = $rawBody === null ? null : OpenFingerprint::maskRawBody($rawBody, $body);
		if ($maskedBody === null)
		{
			return null;
		}
		$capabilities = '';
		foreach (self::OPEN_CAPABILITIES as $name => $value)
		{
			$capabilities .= OpenFingerprint::lengthPrefixed($name) . OpenFingerprint::lengthPrefixed($value);
		}

		// An untrusted endpoint proves no outcome whatever its address, so only the path binds its sends.
		$trusted = $this->isTrustedTransport();

		return OpenFingerprint::digest([
			HttpClient::HTTP_POST,
			($trusted ? $this->baseUrl : 'untrusted-transport') . self::buildOpenPath($docKey),
			$trusted ? strtolower((string)parse_url($this->baseUrl, PHP_URL_HOST)) : '',
			$this->signer->getKeyIdentity(),
			$capabilities,
			$sourceBinding,
			$maskedBody,
		]);
	}

	private static function buildOpenPath(string $docKey): string
	{
		return '/v1/documents/' . self::encodeSegment($docKey) . '/open';
	}

	public function upsertGrants(string $docKey, array $grants): Result
	{
		$path = '/v1/documents/' . self::encodeSegment($docKey) . '/grants';

		return $this->send(HttpClient::HTTP_POST, $path, $grants, route: '/v1/documents/{docKey}/grants', operation: 'upsertGrants');
	}

	public function revokeGrant(string $docKey, string $userId): Result
	{
		$path = '/v1/documents/' . self::encodeSegment($docKey) . '/grants/' . self::encodeSegment($userId);

		return $this->send(HttpClient::HTTP_DELETE, $path, null, route: '/v1/documents/{docKey}/grants/{userId}', operation: 'revokeGrant');
	}

	public function forcesave(string $docKey, ?array $userdata): Result
	{
		$path = '/v1/documents/' . self::encodeSegment($docKey) . '/forcesave';

		return $this->send(HttpClient::HTTP_POST, $path, $userdata, route: '/v1/documents/{docKey}/forcesave', operation: 'forcesave');
	}

	public function updateMeta(string $docKey, string $title): Result
	{
		$path = '/v1/documents/' . self::encodeSegment($docKey) . '/meta';

		return $this->send(HttpClient::HTTP_POST, $path, ['title' => $title], route: '/v1/documents/{docKey}/meta', operation: 'updateMeta');
	}

	public function convert(array $body): Result
	{
		return $this->send(HttpClient::HTTP_POST, '/v1/convert', $body, route: '/v1/convert', operation: 'convert');
	}

	public function signVersionUrls(string $docKey, array $body): Result
	{
		$path = '/v1/documents/' . self::encodeSegment($docKey) . '/versions/sign-urls';

		return $this->send(HttpClient::HTTP_POST, $path, $body, route: '/v1/documents/{docKey}/versions/sign-urls', operation: 'signVersionUrls');
	}

	/**
	 * Percent-encodes a single path segment so that "/" becomes "%2F"
	 * (RFC 3986; rawurlencode does not turn spaces into "+").
	 */
	private static function encodeSegment(string $segment): string
	{
		return rawurlencode($segment);
	}

	/**
	 * Encodes the body, signs the request and sends it through HttpClient.
	 *
	 * @param string $method HTTP method exactly as it goes on the wire
	 * @param string $escapedPath percent-encoded path exactly as it goes on the wire
	 * @param array|null $body payload, or null for a body-less request (e.g. DELETE)
	 */
	private function send(
		string $method,
		string $escapedPath,
		?array $body,
		?float $deadline = null,
		array $extraHeaders = [],
		string $route = '',
		string $operation = '',
	): Result
	{
		$startedAt = hrtime(true);
		$attempt = $operation === 'open'
			? $this->callContext->currentOpenAttempt()
			: $this->callContext->nextAttempt($operation);
		$requestSent = false;
		$httpStatus = null;
		$platformCode = null;
		$diagnosticResult = 'not_sent';
		$diagnosticCode = 'protocol_exception';
		$thrown = false;
		try
		{
			$result = new Result();
			if ($deadline !== null && (!is_finite($deadline) || $deadline - hrtime(true) / 1e9 < 0.001))
			{
				$diagnosticCode = 'vibeoffice_open_deadline';
				return $result->addError(new Error(
					'Vibeoffice opening deadline exceeded.',
					'vibeoffice_open_deadline',
					['requestSent' => false, 'responseVerified' => false],
				));
			}
			if ($deadline !== null && !function_exists('curl_init'))
			{
				$diagnosticCode = 'vibeoffice_deadline_transport';
				return $result->addError(new Error(
					'Opening deadline requires cURL transport.',
					'vibeoffice_deadline_transport',
					['requestSent' => false, 'responseVerified' => false],
				));
			}

			if ($body === null)
			{
				$rawBody = '';
			}
			else
			{
				try
				{
					$rawBody = Json::encode($body);
				}
				catch (ArgumentException $e)
				{
					$diagnosticCode = 'request_encode_failed';
					return $result->addError(new Error($e->getMessage()));
				}
			}

			$ts = time();
			$nonce = Signer::generateNonce();
			$headers = $this->signer->buildHeaders($method, $escapedPath, '', $rawBody, $ts, $nonce);
			$http = $this->httpClientFactory === null ? $this->buildHttpClient($deadline) : ($this->httpClientFactory)($deadline);
			$http->setRedirect(false);
			foreach ($headers as $name => $value)
			{
				$http->setHeader($name, $value);
			}
			foreach ($extraHeaders as $name => $value)
			{
				$http->setHeader($name, $value);
			}

			$url = $this->baseUrl . $escapedPath;
			$entityBody = $rawBody === '' ? null : $rawBody;
			$requestSent = null;
			if ($http->query($method, $url, $entityBody) === false)
			{
				$diagnosticResult = 'error';
				$diagnosticCode = 'vibeoffice_transport_unknown';
				return $result->addError(new Error(
					'Vibeoffice server is not available.',
					'vibeoffice_transport_unknown',
					['responseVerified' => false],
				));
			}
			$requestSent = true;
			$httpStatus = (int)$http->getStatus();
			if ($deadline !== null && hrtime(true) / 1e9 >= $deadline)
			{
				$diagnosticResult = 'error';
				$diagnosticCode = 'vibeoffice_open_deadline';
				return $result->addError(new Error(
					'Vibeoffice opening deadline exceeded.',
					'vibeoffice_open_deadline',
					['responseVerified' => false],
				));
			}

			if ($httpStatus >= 200 && $httpStatus < 300)
			{
				if ($this->isValidDiagnosticResponse($operation, (string)$http->getResult()))
				{
					$diagnosticResult = 'ok';
					$diagnosticCode = null;
				}
				else
				{
					$diagnosticResult = 'error';
					$diagnosticCode = 'response_invalid';
				}
			}
			else
			{
				$diagnosticResult = 'refused';
				$diagnosticCode = 'platform_refused';
				$parsedCode = $this->extractPlatformCode((string)$http->getResult());
				$platformCode = in_array($parsedCode, self::LOGGABLE_PLATFORM_CODES, true) ? $parsedCode : null;
			}

			return $this->handleResponse($result, $http);
		}
		catch (\Throwable $e)
		{
			$thrown = true;
			$diagnosticResult = $requestSent === false ? 'not_sent' : 'error';
			$diagnosticCode = 'protocol_exception';
			throw $e;
		}
		finally
		{
			$level = $thrown || $diagnosticResult === 'error' ? 'error'
				: ($diagnosticResult === 'ok' ? 'info' : 'warning');
			try
			{
				$this->logger->log($level, 'vibeoffice platform request', [
					'method' => $method,
					'path' => $route,
					'httpStatus' => $httpStatus,
					'platformCode' => $platformCode,
					'duration_ms' => max(0, (int)floor((hrtime(true) - $startedAt) / 1e6)),
					'attempt' => $attempt,
					'result' => $diagnosticResult,
					'code' => $diagnosticCode,
					'request_sent' => $requestSent,
					'trace_id' => null,
				]);
			}
			catch (\Throwable)
			{
				// Diagnostic failures do not change the platform result.
			}
		}
	}

	private function isValidDiagnosticResponse(string $operation, string $rawResponse): bool
	{
		if ($rawResponse === '')
		{
			return $operation !== 'open';
		}
		try
		{
			$decoded = json_decode($rawResponse, flags: JSON_THROW_ON_ERROR);
		}
		catch (\JsonException)
		{
			return false;
		}
		if ($operation === 'open')
		{
			return $decoded instanceof \stdClass
				&& isset($decoded->config)
				&& $decoded->config instanceof \stdClass
				&& get_object_vars($decoded->config) !== [];
		}
		return $decoded instanceof \stdClass || is_array($decoded);
	}

	private function handleResponse(Result $result, HttpClient $http): Result
	{
		$status = (int)$http->getStatus();

		if ($status >= 200 && $status < 300)
		{
			$result->setData($this->decodeBody((string)$http->getResult()));

			return $result;
		}

		$retryAfter = self::parseRetryAfter($http->getHeaders()->get('Retry-After'));

		return $result->addError($this->mapError($status, (string)$http->getResult(), $retryAfter));
	}

	/**
	 * Reads Retry-After as delay-seconds only: an HTTP-date, any non-numeric value
	 * and a non-positive delay all give null. An upper bound belongs to the retry
	 * policy, not to the transport.
	 */
	private static function parseRetryAfter(mixed $headerValue): ?int
	{
		if (!is_string($headerValue))
		{
			return null;
		}

		$delaySeconds = trim($headerValue);
		if (preg_match('/^[0-9]+$/D', $delaySeconds) !== 1)
		{
			return null;
		}

		$seconds = (int)$delaySeconds;

		return $seconds > 0 ? $seconds : null;
	}

	/**
	 * Decodes a successful JSON response body into an array, tolerant of an empty
	 * or non-JSON body (returns an empty array).
	 */
	private function decodeBody(string $rawResponse): array
	{
		if ($rawResponse === '')
		{
			return [];
		}

		try
		{
			$decoded = Json::decode($rawResponse);
		}
		catch (ArgumentException $e)
		{
			return [];
		}

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Maps a platform HTTP status (and optional JSON error code in the body)
	 * into a human-readable Bitrix\Main\Error.
	 *
	 * The status, the platform code and the retry delay also go into the error
	 * custom data (DTO-OPENERR) - that structure, and not the message, is what the
	 * controller branches on. The `Code: ...` suffix stays in the message for the logs
	 * and for the callers that still parse it.
	 */
	private function mapError(int $status, string $rawResponse, ?int $retryAfter): Error
	{
		$platformCode = $this->extractPlatformCode($rawResponse);

		$message = match ($status)
		{
			401 => 'Vibeoffice authentication failed (invalid key, replay, bad signature or timestamp skew).',
			403 => 'Vibeoffice access denied: no grant.',
			409 => 'Vibeoffice request conflict (operation in progress or document is full).',
			413 => 'Vibeoffice payload is too large.',
			422 => 'Vibeoffice rejected the request as unprocessable.',
			502 => 'Vibeoffice bad gateway.',
			503 => 'Vibeoffice service is unavailable.',
			default => 'Vibeoffice server returned an error. Status ' . $status . '.',
		};

		if ($platformCode !== null)
		{
			$message .= ' Code: ' . $platformCode . '.';
		}

		return new Error($message, $status, [
			'responseVerified' => true,
			'httpStatus' => $status,
			'platformCode' => $platformCode,
			'retryAfter' => $retryAfter,
		]);
	}

	/**
	 * Extracts the platform error code from the JSON response body, if present.
	 * The platform wraps it into an `{"error": {"code": ...}}` envelope; the flat
	 * shape stays supported. Tolerant of non-JSON or unexpected shapes.
	 */
	private function extractPlatformCode(string $rawResponse): ?string
	{
		if ($rawResponse === '')
		{
			return null;
		}

		try
		{
			$decoded = Json::decode($rawResponse);
		}
		catch (ArgumentException $e)
		{
			return null;
		}

		if (!is_array($decoded))
		{
			return null;
		}

		if (is_array($decoded['error'] ?? null))
		{
			return self::readFirstScalar($decoded['error'], ['code', 'error_code']);
		}

		return self::readFirstScalar($decoded, ['code', 'error', 'error_code']);
	}

	/**
	 * @param string[] $fields
	 */
	private static function readFirstScalar(array $source, array $fields): ?string
	{
		foreach ($fields as $field)
		{
			if (isset($source[$field]) && is_scalar($source[$field]))
			{
				return (string)$source[$field];
			}
		}

		return null;
	}

	private function buildHttpClient(?float $deadline = null): HttpClient
	{
		$options = [
			'socketTimeout' => 5,
			'streamTimeout' => 10,
			'version' => HttpClient::HTTP_1_1,
		];
		$http = $deadline === null ? new HttpClient($options) : new class($options, $deadline) extends HttpClient {
			public function __construct(array $options, private readonly float $deadline)
			{
				parent::__construct($options + ['useCurl' => true]);
			}

			protected function createHandler(\Psr\Http\Message\RequestInterface $request, bool $async = false)
			{
				$remainingMs = (int)floor(($this->deadline - hrtime(true) / 1e9) * 1000);
				if ($remainingMs <= 0)
				{
					throw new \Bitrix\Main\Web\Http\ClientException($request, 'Vibeoffice opening deadline exceeded.');
				}

				// A dedicated handle prevents the opening deadline from affecting other requests.
				$handler = parent::createHandler($request, true);
				curl_setopt($handler->getHandle(), CURLOPT_TIMEOUT_MS, $remainingMs);
				curl_setopt($handler->getHandle(), CURLOPT_CONNECTTIMEOUT_MS, min(5000, $remainingMs));

				return $handler;
			}
		};

		$http->setHeader('Content-Type', 'application/json');
		$http->setRedirect(false);

		return $http;
	}
}
