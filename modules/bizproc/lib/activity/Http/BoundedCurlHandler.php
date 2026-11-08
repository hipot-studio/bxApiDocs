<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Http;

use Bitrix\Main\Web\Http\Curl\Handler;
use Bitrix\Main\Web\Http\NetworkException;
use Bitrix\Main\Web\Http\Response;
use Bitrix\Main\Web\IpAddress;
use Psr\Http\Message\UriInterface;

/**
 * Curl transport bounded by a wall-clock deadline and a response headers limit.
 * Behind a proxy it tunnels to the checked address instead of letting the proxy resolve the host again.
 */
class BoundedCurlHandler extends Handler
{
	private int $headersLengthMax = 0;
	private bool $headersOverflow = false;

	public static function connectTo(UriInterface $uri, IpAddress $ip): string
	{
		$port = $uri->getPort();

		return "{$uri->getHost()}:{$port}:{$ip}:{$port}";
	}

	public function execute(): Response
	{
		try
		{
			return parent::execute();
		}
		catch (NetworkException $e)
		{
			if ($this->headersOverflow)
			{
				throw new NetworkException($this->request, 'Maximum response headers length has been reached.');
			}

			throw $e;
		}
	}

	/**
	 * Curl hands over the informational responses too, and the parent would parse the first of them as the final one.
	 * Returning a length other than the received one makes curl abort the transfer at once.
	 */
	public function receiveHeaders($handle, $data)
	{
		if (str_starts_with($data, 'HTTP/') && preg_match('#^HTTP/\d(?:\.\d)? 1\d\d#', $this->responseHeaders))
		{
			$this->responseHeaders = '';
		}

		if ($this->headersLengthMax > 0 && strlen($this->responseHeaders) + strlen($data) > $this->headersLengthMax)
		{
			$this->headersOverflow = true;

			return 0;
		}

		return parent::receiveHeaders($handle, $data);
	}

	protected function setOptions(array $options): void
	{
		parent::setOptions($options);

		$this->headersLengthMax = (int)($options['headersLengthMax'] ?? 0);

		$curlOptions = [
			CURLOPT_TIMEOUT => (int)($options['deadline'] ?? 0),
		];

		if (!empty($options['proxyHost']))
		{
			$curlOptions[CURLOPT_CONNECT_TO] = [static::connectTo($this->request->getUri(), $options['effectiveIp'])];
			$curlOptions[CURLOPT_HTTPPROXYTUNNEL] = true;
			$curlOptions[CURLOPT_SUPPRESS_CONNECT_HEADERS] = true;
			if (($options['proxyUser'] ?? '') !== '')
			{
				$curlOptions[CURLOPT_PROXYUSERPWD] = $options['proxyUser'] . ':' . ($options['proxyPassword'] ?? '');
			}
		}

		curl_setopt_array($this->handle, $curlOptions);
	}
}
