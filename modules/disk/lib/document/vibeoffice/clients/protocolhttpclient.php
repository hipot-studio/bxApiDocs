<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Clients;

use Bitrix\Main\Web\Http;
use Bitrix\Main\Web\HttpClient;
use Psr\Http\Message\RequestInterface;

class ProtocolHttpClient extends HttpClient
{
	private ?Http\Handler $observedHandler = null;
	private array $transportDiagnostics = [];

	public function __construct(
		?array $options = null,
		private readonly ?float $deadline = null,
	)
	{
		parent::__construct($options);
	}

	public function query($method, $url, $entityBody = null)
	{
		$this->observedHandler = null;
		$this->transportDiagnostics = [];
		$this->request = null;
		$this->response = null;
		$completed = false;
		try
		{
			$completed = parent::query($method, $url, $entityBody);

			return $completed;
		}
		finally
		{
			try
			{
				$this->transportDiagnostics = self::unavailableDiagnostics($this->getError(), !$completed);
				$this->observeTransport();
			}
			catch (\Throwable)
			{
				// Observation must preserve the original transport result or exception.
			}
			finally
			{
				$this->observedHandler = null;
			}
		}
	}

	protected function createHandler(RequestInterface $request, bool $async = false)
	{
		$remainingMs = null;
		if ($this->deadline !== null)
		{
			$remainingMs = (int)floor(($this->deadline - hrtime(true) / 1e9) * 1000);
			if ($remainingMs <= 0)
			{
				throw new Http\ClientException($request, 'Vibeoffice opening deadline exceeded.');
			}

			if (!$this->useCurl)
			{
				$remainingSec = max(1, (int)ceil($remainingMs / 1000));
				$this->socketTimeout = min(5, $remainingSec);
				$this->streamTimeout = $remainingSec;
			}
		}

		$handler = $this->deadline !== null && !$this->useCurl
			? $this->createDeadlineSocketHandler($request, $async)
			: parent::createHandler($request, $this->deadline !== null && $this->useCurl ? true : $async);
		if ($remainingMs !== null && $this->useCurl)
		{
			curl_setopt($handler->getHandle(), CURLOPT_TIMEOUT_MS, $remainingMs);
			curl_setopt($handler->getHandle(), CURLOPT_CONNECTTIMEOUT_MS, min(5000, $remainingMs));
		}

		return $this->observedHandler = $handler;
	}

	private function createDeadlineSocketHandler(RequestInterface $request, bool $async): Http\Socket\Handler
	{
		$handlerOptions = [
			'waitResponse' => $this->waitResponse,
			'bodyLengthMax' => $this->bodyLengthMax,
			'proxyHost' => $this->proxyHost,
			'proxyPort' => $this->proxyPort,
			'effectiveIp' => $this->effectiveIp,
			'contextOptions' => $this->contextOptions,
			'socketTimeout' => $this->socketTimeout,
			'streamTimeout' => $this->streamTimeout,
			'async' => $async,
			'curlLogFile' => $this->curlLogFile,
		];
		if (!isset($this->responseBuilder))
		{
			$this->responseBuilder = new Http\ResponseBuilder();
		}
		$handler = new DeadlineSocketHandler($request, $this->responseBuilder, $handlerOptions, (float)$this->deadline);
		if ($this->logger !== null)
		{
			$handler->setLogger($this->logger);
			$handler->setDebugLevel($this->debugLevel);
		}
		if ($this->shouldFetchBody !== null)
		{
			$handler->shouldFetchBody($this->shouldFetchBody);
		}

		return $handler;
	}

	public function getTransportDiagnostics(): array
	{
		return $this->transportDiagnostics;
	}

	public static function unavailableDiagnostics(array $errors, bool $failed): array
	{
		$frameworkCode = null;
		foreach (['NETWORK', 'URI_SCHEME', 'URI_HOST', 'URI_PUNICODE', 'PRIVATE_IP', 'REDIRECT', 'MULTIPART'] as $code)
		{
			if (array_key_exists($code, $errors))
			{
				$frameworkCode = $code;

				break;
			}
		}
		$frameworkCode ??= $errors === [] ? null : 'OTHER';

		return [
			'transport_kind' => 'unknown',
			'transport_error' => !$failed ? null
				: (in_array($frameworkCode, ['URI_SCHEME', 'URI_HOST', 'URI_PUNICODE', 'REDIRECT', 'MULTIPART'], true)
					? 'protocol' : 'unknown'),
			'transport_errno' => null,
			'transport_framework_code' => $frameworkCode,
			'response_headers_received' => null,
			'observed_http_status' => null,
			'transport_dns_ms' => null,
			'transport_connect_ms' => null,
			'transport_tls_ms' => null,
			'transport_first_byte_ms' => null,
		];
	}

	private function observeTransport(): void
	{
		$handler = $this->observedHandler;
		if ($handler === null)
		{
			return;
		}
		$diagnostics = &$this->transportDiagnostics;
		if ($handler instanceof Http\Curl\Handler)
		{
			$diagnostics['transport_kind'] = 'curl';
			$handle = $handler->getHandle();
			$errno = curl_errno($handle);
			$info = curl_getinfo($handle);
			$diagnostics['transport_errno'] = $errno > 0 && $errno <= 65535 ? $errno : null;
			if ($diagnostics['transport_error'] !== null)
			{
				$diagnostics['transport_error'] = match ($errno)
				{
					5, 6 => 'dns',
					7 => 'connect',
					28 => 'timeout',
					35, 51, 58, 60, 77, 90 => 'tls',
					55 => 'write',
					56 => 'read',
					1, 3, 8 => 'protocol',
					default => 'unknown',
				};
			}
			$status = $info['http_code'] ?? 0;
			$diagnostics['observed_http_status'] = $status >= 100 && $status <= 599 ? $status : null;
			$diagnostics['response_headers_received'] = $diagnostics['observed_http_status'] !== null;
			$dns = $info['namelookup_time'] ?? null;
			$connect = $info['connect_time'] ?? null;
			$tls = curl_getinfo($handle, CURLINFO_APPCONNECT_TIME);
			$diagnostics['transport_dns_ms'] = self::milliseconds($dns);
			$diagnostics['transport_connect_ms'] = self::phaseMilliseconds($connect, $dns);
			$diagnostics['transport_tls_ms'] = $handler->getRequest()->getUri()->getScheme() === 'https'
				&& is_numeric($tls) && $tls > 0 ? self::phaseMilliseconds($tls, $connect) : null;
			$firstByte = $info['starttransfer_time'] ?? null;
			$diagnostics['transport_first_byte_ms'] = is_numeric($firstByte) && $firstByte > 0
				? self::milliseconds($firstByte) : null;
		}
		elseif ($handler instanceof Http\Socket\Handler)
		{
			$diagnostics['transport_kind'] = 'stream';
			$status = $handler->getResponse()?->getStatusCode();
			$diagnostics['observed_http_status'] = $status !== null && $status >= 100 && $status <= 599
				? $status : null;
			$diagnostics['response_headers_received'] = $diagnostics['observed_http_status'] !== null;
		}
	}

	private static function phaseMilliseconds(mixed $end, mixed $start): ?int
	{
		return is_numeric($end) && is_numeric($start) && $end > 0 && $start >= 0 && $end >= $start
			? self::milliseconds($end - $start) : null;
	}

	private static function milliseconds(mixed $seconds): ?int
	{
		return (is_int($seconds) || is_float($seconds)) && is_finite((float)$seconds) && $seconds >= 0
			? (int)min(2147483647, floor($seconds * 1000)) : null;
	}
}

/**
 * Enforces a single monotonic absolute deadline across connect, TLS handshake, write, and chunked reads
 * when cURL is unavailable.
 */
final class DeadlineSocketHandler extends Http\Socket\Handler
{
	private float $deadline;

	public function __construct(
		RequestInterface $request,
		Http\ResponseBuilderInterface $responseBuilder,
		array $options,
		float $deadline,
	)
	{
		$this->deadline = $deadline;
		parent::__construct($request, $responseBuilder, $options);
	}

	protected function createSocket(array $options): Http\Socket\Stream
	{
		$proxyHost = (string)($options['proxyHost'] ?? '');
		$proxyPort = (int)($options['proxyPort'] ?? 80);
		$contextOptions = $options['contextOptions'] ?? [];
		$uri = $this->request->getUri();

		if ($proxyHost !== '')
		{
			$host = $proxyHost;
			$port = $proxyPort;
			$contextOptions['ssl']['peer_name'] = $uri->getHost();
		}
		else
		{
			$host = $uri->getHost();
			$port = $uri->getPort();
			if (isset($options['effectiveIp']) && $options['effectiveIp'] instanceof \Bitrix\Main\Web\IpAddress)
			{
				$contextOptions['ssl']['peer_name'] = $host;
				$host = $options['effectiveIp']->get();
			}
		}

		return new DeadlineSocketStream(
			'tcp://' . $host . ':' . $port,
			[
				'socketTimeout' => $options['socketTimeout'] ?? null,
				'streamTimeout' => $options['streamTimeout'] ?? null,
				'contextOptions' => $contextOptions,
				'async' => $options['async'] ?? null,
			],
			$this->deadline,
		);
	}
}

final class DeadlineSocketStream extends Http\Socket\Stream
{
	public function __construct(
		string $address,
		array $options,
		private readonly float $deadline,
	)
	{
		parent::__construct($address, $options);
	}

	public function connect(): void
	{
		$remaining = $this->remainingSeconds('Socket connection timeout has been reached.');
		$connectTimeout = min(5.0, $remaining);
		$context = stream_context_create($this->contextOptions);
		$flags = STREAM_CLIENT_CONNECT;
		if ($this->async)
		{
			$flags |= STREAM_CLIENT_ASYNC_CONNECT;
		}

		set_error_handler(static function ($errno, $errstr): never {
			throw new \RuntimeException("[{$errno}] {$errstr}");
		});
		try
		{
			$res = $context
				? stream_socket_client($this->address, $errno, $errstr, $connectTimeout, $flags, $context)
				: stream_socket_client($this->address, $errno, $errstr, $connectTimeout, $flags);
		}
		finally
		{
			restore_error_handler();
		}

		if (!is_resource($res))
		{
			throw new \RuntimeException($errno > 0 ? "[{$errno}] {$errstr}" : 'Socket connection error.');
		}

		$this->resource = $res;
		$this->applyRemainingStreamTimeout('Socket connection timeout has been reached.');
		if ($this->async)
		{
			$this->setBlocking(false);
		}
	}

	public function enableCrypto(bool $enable = true)
	{
		try
		{
			$this->applyRemainingStreamTimeout('Error establishing an SSL connection.');
		}
		catch (\RuntimeException)
		{
			return false;
		}
		$result = parent::enableCrypto($enable);
		if ($this->timedOut())
		{
			return false;
		}

		return $result;
	}

	public function write(string $string): int
	{
		$this->applyRemainingStreamTimeout('Stream writing timeout has been reached.');

		set_error_handler(static function ($errno, $errstr): never {
			throw new \RuntimeException($errstr);
		});
		try
		{
			$result = Http\Stream::write($string);
		}
		finally
		{
			restore_error_handler();
		}

		if ($this->timedOut())
		{
			throw new \RuntimeException('Stream writing timeout has been reached.');
		}

		return $result;
	}

	public function gets()
	{
		$this->applyRemainingStreamTimeout('Stream reading timeout has been reached.');
		$result = fgets($this->resource);
		if ($this->timedOut())
		{
			throw new \RuntimeException('Stream reading timeout has been reached.');
		}

		return $result;
	}

	public function read(int $length): string
	{
		$this->applyRemainingStreamTimeout('Stream reading timeout has been reached.');
		$result = Http\Stream::read($length);
		if ($this->timedOut())
		{
			throw new \RuntimeException('Stream reading timeout has been reached.');
		}

		return $result;
	}

	public function timedOut(): bool
	{
		if (is_resource($this->resource) && $this->getMetadata('timed_out'))
		{
			return true;
		}

		return hrtime(true) / 1e9 >= $this->deadline;
	}

	private function applyRemainingStreamTimeout(string $timeoutMessage): void
	{
		$remaining = $this->remainingSeconds($timeoutMessage);
		if (is_resource($this->resource))
		{
			$seconds = (int)floor($remaining);
			$microseconds = max(1, (int)floor(($remaining - $seconds) * 1_000_000));
			stream_set_timeout($this->resource, $seconds, $seconds === 0 ? $microseconds : (int)floor(($remaining - $seconds) * 1_000_000));
		}
	}

	private function remainingSeconds(string $timeoutMessage): float
	{
		$remaining = $this->deadline - hrtime(true) / 1e9;
		if ($remaining <= 0.0)
		{
			throw new \RuntimeException($timeoutMessage);
		}

		return $remaining;
	}
}
