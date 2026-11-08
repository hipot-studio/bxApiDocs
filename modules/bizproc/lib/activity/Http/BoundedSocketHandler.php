<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Http;

use Bitrix\Main\Web\Http\NetworkException;
use Bitrix\Main\Web\Http\Socket\Handler;
use Bitrix\Main\Web\Http\Socket\Stream;
use Bitrix\Main\Web\IpAddress;

/** Socket transport without proxy support: the client sends proxied requests through curl. */
class BoundedSocketHandler extends Handler
{
	protected function createSocket(array $options): Stream
	{
		$uri = $this->request->getUri();
		$host = $uri->getHost();
		$contextOptions = $options['contextOptions'] ?? [];

		if (isset($options['effectiveIp']) && $options['effectiveIp'] instanceof IpAddress)
		{
			$contextOptions['ssl']['peer_name'] = $host;
			$host = $options['effectiveIp']->get();
		}

		return new BoundedSocketStream("tcp://{$host}:{$uri->getPort()}", [
			'socketTimeout' => $options['socketTimeout'] ?? null,
			'streamTimeout' => $options['streamTimeout'] ?? null,
			'contextOptions' => $contextOptions,
			'async' => $options['async'] ?? null,
			'deadline' => $options['deadline'] ?? 0,
			'headersLengthMax' => $options['headersLengthMax'] ?? 0,
		]);
	}

	/** The parent takes the first header block for the final response, informational ones included. */
	protected function receiveHeaders(): bool
	{
		while (parent::receiveHeaders())
		{
			if (!preg_match('#^HTTP/\d(?:\.\d)? 1\d\d#', $this->responseHeaders))
			{
				return true;
			}
			$this->responseHeaders = '';
		}

		return false;
	}

	/** The parent treats a closed connection as the end of the body even when the framing promised more. */
	protected function receiveBody(): bool
	{
		if (!parent::receiveBody())
		{
			return false;
		}

		$length = $this->response->getHeadersCollection()->get('Content-Length');
		if ($length !== null && $this->socket instanceof BoundedSocketStream && $this->socket->getReadLength() < (int)$length)
		{
			throw new NetworkException($this->request, 'Connection closed before the whole content length was received.');
		}

		if ($this->responseBuilder instanceof BoundedResponseBuilder && !$this->responseBuilder->isBodyComplete())
		{
			throw new NetworkException($this->request, 'Connection closed before the last chunk was received.');
		}

		return true;
	}
}
