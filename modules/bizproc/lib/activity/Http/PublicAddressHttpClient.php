<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Http;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\HttpClient;
use Psr\Http\Message\RequestInterface;

class PublicAddressHttpClient extends HttpClient
{
	private const SHARED_ADDRESS_SPACE = '100.64.0.0/10';
	private const DENIED_RANGES_OPTION = 'outgoing_request_denied_ranges';
	private const PROXY_OPTION = 'outgoing_request_proxy';

	private int $deadline = 0;
	private int $headersLengthMax = 0;

	public function __construct(?array $options = null)
	{
		parent::__construct($options);
		parent::setPrivateIp(false);
		$this->applyProxyOption();
	}

	public function setPrivateIp($value)
	{
		return parent::setPrivateIp(false);
	}

	/** Wall-clock limit for the whole request in seconds, 0 for none. */
	public function setDeadline(int $seconds): static
	{
		$this->deadline = max(0, $seconds);

		return $this;
	}

	public function setHeadersLengthMax(int $length): static
	{
		$this->headersLengthMax = max(0, $length);

		return $this;
	}

	protected function checkRequest(RequestInterface $request): bool
	{
		if (!parent::checkRequest($request))
		{
			return false;
		}

		$ip = $this->effectiveIp;
		if (!$ip->isIPv4() || $ip->matchRange(self::SHARED_ADDRESS_SPACE))
		{
			$this->addError('PRIVATE_IP', "Resolved IP is not a public IPv4 address: {$ip}");

			return false;
		}

		foreach ($this->getDeniedRanges() as $range)
		{
			if ($ip->matchRange($range))
			{
				$this->addError('DENIED_IP', "Resolved IP is denied for outgoing requests: {$ip}");

				return false;
			}
		}

		if ($this->proxyHost !== '' && !function_exists('curl_init'))
		{
			$this->addError('PROXY_TRANSPORT', 'The curl extension is required to pin the resolved IP through the proxy');

			return false;
		}

		return true;
	}

	protected function createHandler(RequestInterface $request, bool $async = false)
	{
		if ($this->sslVerify === false)
		{
			$this->contextOptions['ssl']['verify_peer_name'] = false;
			$this->contextOptions['ssl']['verify_peer'] = false;
			$this->contextOptions['ssl']['allow_self_signed'] = true;
		}

		$options = [
			'waitResponse' => $this->waitResponse,
			'bodyLengthMax' => $this->bodyLengthMax,
			'proxyHost' => $this->proxyHost,
			'proxyPort' => $this->proxyPort,
			'proxyUser' => $this->proxyUser,
			'proxyPassword' => $this->proxyPassword,
			'effectiveIp' => $this->effectiveIp,
			'contextOptions' => $this->contextOptions,
			'socketTimeout' => $this->socketTimeout,
			'streamTimeout' => $this->streamTimeout,
			'async' => $async,
			'curlLogFile' => $this->curlLogFile,
			'deadline' => $this->deadline,
			'headersLengthMax' => $this->headersLengthMax,
		];
		$this->responseBuilder ??= new BoundedResponseBuilder($this->bodyLengthMax);

		if ($this->proxyHost !== '' || $this->useCurl)
		{
			$request = $request->withoutHeader('Proxy-Authorization');
			$handler = new BoundedCurlHandler($request, $this->responseBuilder, $options);
		}
		else
		{
			$handler = new BoundedSocketHandler($request, $this->responseBuilder, $options);
		}

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

	/** @return list<string> */
	private function getDeniedRanges(): array
	{
		$configured = (string)Option::get('bizproc', self::DENIED_RANGES_OPTION, '');

		return preg_split('/[\s,;]+/', $configured, -1, PREG_SPLIT_NO_EMPTY);
	}

	private function applyProxyOption(): void
	{
		$proxy = trim((string)Option::get('bizproc', self::PROXY_OPTION, ''));
		if ($proxy === '')
		{
			return;
		}

		$parts = parse_url('http://' . $proxy);
		if (!is_array($parts) || empty($parts['host']))
		{
			return;
		}

		$this->setProxy($parts['host'], $parts['port'] ?? null, $parts['user'] ?? null, $parts['pass'] ?? null);
	}
}
