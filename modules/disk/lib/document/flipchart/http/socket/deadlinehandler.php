<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Http\Socket;

use Bitrix\Disk\Document\Flipchart\Http\RequestDeadline;
use Bitrix\Main\Web\Http;
use Bitrix\Main\Web\IpAddress;

final class DeadlineHandler extends Http\Socket\Handler
{
	public function __construct(
		\Psr\Http\Message\RequestInterface $request,
		Http\ResponseBuilderInterface $responseBuilder,
		array $options,
		private readonly RequestDeadline $deadline,
	)
	{
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
			if (isset($options['effectiveIp']) && $options['effectiveIp'] instanceof IpAddress)
			{
				$contextOptions['ssl']['peer_name'] = $host;
				$host = $options['effectiveIp']->get();
			}
		}

		return new DeadlineStream(
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
