<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Http\Curl;

use Bitrix\Disk\Document\Flipchart\Http\RequestDeadline;
use Bitrix\Main\Web\Http;

final class DeadlineHandler extends Http\Curl\Handler
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

	protected function setOptions(array $options): void
	{
		parent::setOptions($options);
		curl_setopt($this->handle, CURLOPT_TIMEOUT_MS, $this->deadline->getRemainingMilliseconds());
	}

	public function execute(): Http\Response
	{
		try
		{
			return parent::execute();
		}
		catch (Http\NetworkException $exception)
		{
			if (curl_errno($this->handle) === CURLE_OPERATION_TIMEDOUT)
			{
				$this->deadline->markExceeded();
			}

			throw $exception;
		}
	}
}
