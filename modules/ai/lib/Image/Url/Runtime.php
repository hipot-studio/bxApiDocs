<?php

namespace Bitrix\AI\Image\Url;

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\IpAddress;
use CFile;
use CTempFile;
use Psr\Http\Message\RequestInterface;

final class Runtime implements RuntimeInterface
{
	private const MAX_BODY_LENGTH = 20_000_000;
	private const TOTAL_TIMEOUT = 60;
	private const IMAGE_EXTENSIONS = [
		IMAGETYPE_BMP => 'bmp',
		IMAGETYPE_GIF => 'gif',
		IMAGETYPE_JPEG => 'jpg',
		IMAGETYPE_PNG => 'png',
		IMAGETYPE_WEBP => 'webp',
	];
	private ?int $deadline = null;
	private readonly bool $useCurl;
	private readonly \Closure $resolver;
	private readonly int $totalTimeout;
	private readonly \Closure $peerAddressReader;
	private string $lastPeerSource = '';
	private string $lastEffectiveUrl = '';

	public function __construct(
		?bool $useCurl = null,
		?callable $resolver = null,
		int $totalTimeout = self::TOTAL_TIMEOUT,
		?callable $peerAddressReader = null,
		private readonly ?string $caFile = null,
	)
	{
		$this->useCurl = $useCurl ?? function_exists('curl_init');
		$this->resolver = $resolver !== null
			? \Closure::fromCallable($resolver)
			: static fn(string $host) => @gethostbynamel($host)
		;
		$this->totalTimeout = max(1, $totalTimeout);
		$this->peerAddressReader = $peerAddressReader !== null
			? \Closure::fromCallable($peerAddressReader)
			: static function(object $handler): array {
				if ($handler instanceof \Bitrix\Main\Web\Http\Curl\Handler)
				{
					return [
						'address' => (string)curl_getinfo($handler->getHandle(), CURLINFO_PRIMARY_IP),
						'source' => 'curl-primary-ip',
						'url' => (string)curl_getinfo($handler->getHandle(), CURLINFO_EFFECTIVE_URL),
					];
				}

				if ($handler instanceof \Bitrix\Main\Web\Http\Socket\Handler)
				{
					$socket = $handler->getSocket();

					return [
						'address' => $socket instanceof DeadlineSocketStream ? $socket->getPeerAddress() : '',
						'source' => 'socket-peer-address',
						'url' => method_exists($handler, 'getTransportUrl') ? $handler->getTransportUrl() : '',
					];
				}

				return ['address' => '', 'source' => ''];
			}
		;
	}

	public function usesProxy(): bool
	{
		$options = Configuration::getValue('http_client_options');
		if (is_array($options) && (string)($options['proxyHost'] ?? '') !== '')
		{
			return true;
		}

		return false;
	}

	public function resolve(string $host): array
	{
		$this->startDeadline();
		if ($this->getRemainingTimeout() === null)
		{
			return $this->dnsFailure();
		}

		$addresses = ($this->resolver)($host);
		if (!is_array($addresses) || $this->getRemainingTimeout() === null)
		{
			return $this->dnsFailure();
		}

		return [
			'success' => true,
			'truncated' => false,
			'addresses' => array_values(array_unique($addresses)),
		];
	}

	public function request(string $url, string $pinnedIp): array
	{
		$this->startDeadline();
		$this->lastPeerSource = '';
		$this->lastEffectiveUrl = '';
		$timeout = $this->getRemainingTimeout();
		if ($timeout === null)
		{
			return $this->requestFailure($url);
		}

		$client = $this->createHttpClient($pinnedIp, $timeout);

		$body = $client->get($url);
		$headers = [];
		foreach ($client->getHeaders()->toArray() as $name => $header)
		{
			$headers[strtolower($name)] = $header['values'][0] ?? '';
		}

		$effectiveIp = (string)$client->getPeerAddress();

		return [
			'success' => $body !== false,
			'status' => (int)$client->getStatus(),
			'headers' => $headers,
			'body' => is_string($body) ? $body : '',
			'effectiveIp' => $effectiveIp,
			'peerSource' => $this->lastPeerSource,
			'effectiveUrl' => $this->lastEffectiveUrl,
		];
	}

	private function createHttpClient(string $pinnedIp, int $timeout): HttpClient
	{
		return new class(
			$pinnedIp,
			$timeout,
			$this->deadline ?? hrtime(true) + $timeout * 1_000_000_000,
			function(object $handler): array {
				$peer = ($this->peerAddressReader)($handler);
				$this->lastPeerSource = is_string($peer['source'] ?? null) ? $peer['source'] : '';
				$this->lastEffectiveUrl = is_string($peer['url'] ?? null) ? $peer['url'] : '';

				return $peer;
			},
			$this->caFile,
			[
				'redirect' => false,
				'proxyHost' => '',
				'privateIp' => true,
				'socketTimeout' => min(10, $timeout),
				'streamTimeout' => $timeout,
				'bodyLengthMax' => self::MAX_BODY_LENGTH,
				'sendEvents' => false,
				'useCurl' => $this->useCurl,
				'disableSslVerification' => false,
				'headers' => [],
				'cookies' => [],
			],
		) extends HttpClient {
			private ?\Bitrix\Main\Web\Http\Handler $transportHandler = null;

			public function __construct(
				private readonly string $pinnedIp,
				private readonly int $totalTimeout,
				private readonly int $deadline,
				private readonly \Closure $peerAddressReader,
				private readonly ?string $caFile,
				array $options,
			)
			{
				parent::__construct($options);
			}

			protected function checkRequest(RequestInterface $request): bool
			{
				if (!parent::checkRequest($request))
				{
					return false;
				}

				$this->effectiveIp = new IpAddress($this->pinnedIp);

				return true;
			}

			protected function createHandler(RequestInterface $request, bool $async = false)
			{
				if ($this->useCurl)
				{
					$handler = parent::createHandler($request, $async);
					curl_setopt($handler->getHandle(), CURLOPT_TIMEOUT, $this->totalTimeout);
					curl_setopt($handler->getHandle(), CURLOPT_HTTP_TRANSFER_DECODING, false);
					if ($this->caFile !== null)
					{
						curl_setopt($handler->getHandle(), CURLOPT_CAINFO, $this->caFile);
					}
					$this->transportHandler = $handler;

					return $handler;
				}
				if (!isset($this->responseBuilder))
				{
					$this->responseBuilder = new \Bitrix\Main\Web\Http\ResponseBuilder();
				}

				$handler = new class(
					$request,
					$this->responseBuilder,
					[
						'waitResponse' => $this->waitResponse,
						'bodyLengthMax' => $this->bodyLengthMax,
						'proxyHost' => '',
						'effectiveIp' => $this->effectiveIp,
						'contextOptions' => $this->contextOptions,
						'socketTimeout' => $this->socketTimeout,
						'streamTimeout' => $this->streamTimeout,
						'async' => $async,
					],
					$this->deadline,
					$this->caFile,
				) extends \Bitrix\Main\Web\Http\Socket\Handler {
					public function __construct(
						RequestInterface $request,
						\Bitrix\Main\Web\Http\ResponseBuilderInterface $responseBuilder,
						array $options,
						private readonly int $deadline,
						private readonly ?string $caFile,
					)
					{
						parent::__construct($request, $responseBuilder, $options);
					}

					public function execute(): \Bitrix\Main\Web\Http\Response
					{
						while ($this->state !== self::BODY_RECEIVED)
						{
							if (hrtime(true) >= $this->deadline)
							{
								throw new \Bitrix\Main\Web\Http\NetworkException(
									$this->request,
									'Total request timeout has been reached.',
								);
							}

							$this->process();
						}

						return $this->response;
					}

					public function getTransportUrl(): string
					{
						return (string)$this->request->getUri();
					}

					protected function createSocket(array $options): \Bitrix\Main\Web\Http\Socket\Stream
					{
						$contextOptions = $options['contextOptions'] ?? [];
						$uri = $this->request->getUri();
						$host = $uri->getHost();
						$port = $uri->getPort();

						if (isset($options['effectiveIp']) && $options['effectiveIp'] instanceof IpAddress)
						{
							$contextOptions['ssl']['peer_name'] = $host;
							$host = $options['effectiveIp']->get();
						}
						if ($this->caFile !== null)
						{
							$contextOptions['ssl']['cafile'] = $this->caFile;
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
				};

				if ($this->logger !== null)
				{
					$handler->setLogger($this->logger);
					$handler->setDebugLevel($this->debugLevel);
				}
				if ($this->shouldFetchBody !== null)
				{
					$handler->shouldFetchBody($this->shouldFetchBody);
				}
				$this->transportHandler = $handler;

				return $handler;
			}

			public function getPeerAddress()
			{
				if ($this->transportHandler === null)
				{
					return false;
				}

				$peer = ($this->peerAddressReader)($this->transportHandler);

				return is_string($peer['address'] ?? null) && $peer['address'] !== ''
					? $peer['address']
					: false
				;
			}
		};
	}

	public function checkAndSaveImage(string $content, string $moduleId, string $sourceName): ?int
	{
		$extension = $this->detectImageExtension($content);
		if ($extension === null)
		{
			return null;
		}

		$sourceName = $this->normalizeSourceName($sourceName, $extension);
		$tempPath = $this->createTemporaryFile($content, $sourceName);
		if ($tempPath === null)
		{
			return null;
		}

		try
		{
			$file = CFile::MakeFileArray($tempPath, false, true);
			if (!is_array($file))
			{
				return null;
			}

			$file['MODULE_ID'] = $moduleId;
			$file['name'] = $sourceName;
			if (CFile::CheckImageFile($file, 0, 0, 0, ['IMAGE']) !== null)
			{
				return null;
			}

			$fileId = (int)CFile::SaveFile($file, $moduleId);

			return $fileId > 0 ? $fileId : null;
		}
		finally
		{
			$this->removeTemporaryFile($tempPath);
		}
	}

	private function detectImageExtension(string $content): ?string
	{
		$imageInfo = @getimagesizefromstring($content);
		if (!is_array($imageInfo))
		{
			return null;
		}

		return self::IMAGE_EXTENSIONS[$imageInfo[2] ?? 0] ?? null;
	}

	private function createTemporaryFile(string $content, string $name): ?string
	{
		$tempPath = CTempFile::GetFileName('/' . $name);
		$file = new File($tempPath);

		try
		{
			$file->putContents($content);
		}
		catch (\Throwable)
		{
			$this->removeTemporaryFile($tempPath);

			return null;
		}

		return $tempPath;
	}

	private function removeTemporaryFile(string $tempPath): void
	{
		try
		{
			$file = new File($tempPath);
			if ($file->isExists())
			{
				$file->delete();
			}

			$directory = new Directory(dirname($tempPath));
			if ($directory->isExists())
			{
				$directory->delete();
			}
		}
		catch (\Throwable)
		{
		}
	}

	private function normalizeSourceName(string $sourceName, string $extension): string
	{
		$name = preg_replace('/[^a-z0-9._-]+/i', '_', basename($sourceName));
		$name = trim((string)$name, '._-');
		if ($name === '')
		{
			$name = 'image';
		}

		$name = preg_replace('/\.[^.]*$/', '', $name);

		return substr((string)$name, 0, 100) . '.' . $extension;
	}

	private function dnsFailure(): array
	{
		return [
			'success' => false,
			'truncated' => false,
			'addresses' => [],
		];
	}

	private function requestFailure(string $url): array
	{
		return [
			'success' => false,
			'status' => 0,
			'headers' => [],
			'body' => '',
			'effectiveIp' => '',
			'peerSource' => '',
			'effectiveUrl' => $url,
		];
	}

	private function startDeadline(): void
	{
		$this->deadline ??= hrtime(true) + $this->totalTimeout * 1_000_000_000;
	}

	private function getRemainingTimeout(): ?int
	{
		$remainingNanoseconds = ($this->deadline ?? 0) - hrtime(true);
		if ($remainingNanoseconds <= 0)
		{
			return null;
		}

		return (int)ceil($remainingNanoseconds / 1_000_000_000);
	}
}
