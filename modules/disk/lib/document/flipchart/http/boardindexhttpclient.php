<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Http;

use Bitrix\Main\Web\Http;
use Bitrix\Main\Web\HttpClient;
use Psr\Http\Message\RequestInterface;

class BoardIndexHttpClient extends HttpClient
{
	private ?RequestDeadline $deadline = null;
	private bool $deadlineExceeded = false;

	public function __construct(
		private readonly RequestDeadline $requestDeadline,
		?array $options = null,
	)
	{
		parent::__construct($options);
	}

	public function query($method, $url, $entityBody = null)
	{
		$this->deadline = $this->requestDeadline;
		$this->deadlineExceeded = false;
		if ($this->deadline->isExceeded())
		{
			$this->deadlineExceeded = true;
			$this->addError('NETWORK', 'Board indexing request deadline has been reached.');

			return false;
		}

		try
		{
			return parent::query($method, $url, $entityBody);
		}
		catch (\RuntimeException $exception)
		{
			if (!$this->deadline->isExceeded())
			{
				throw $exception;
			}

			$this->deadline->markExceeded();
			$this->addError('NETWORK', 'Board indexing request deadline has been reached.');

			return false;
		}
		finally
		{
			$this->deadlineExceeded = $this->deadline->isExceeded();
			$this->deadline = null;
		}
	}

	public function hasExceededDeadline(): bool
	{
		return $this->deadlineExceeded;
	}

	protected function createHandler(RequestInterface $request, bool $async = false)
	{
		$deadline = $this->deadline ?? $this->requestDeadline;
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

		$handler = $this->useCurl
			? new Curl\DeadlineHandler($request, $this->responseBuilder, $handlerOptions, $deadline)
			: new Socket\DeadlineHandler($request, $this->responseBuilder, $handlerOptions, $deadline);
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
}
