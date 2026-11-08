<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Http;

use Bitrix\Main\Web\Http\DechunkStream;
use Bitrix\Main\Web\Http\Response;
use Bitrix\Main\Web\Http\ResponseBuilder;
use Bitrix\Main\Web\HttpHeaders;

class BoundedResponseBuilder extends ResponseBuilder
{
	private ?ChunkedFramingStream $chunkedBody = null;

	public function __construct(private readonly int $bodyLengthMax)
	{
	}

	/** Whether the body of the last built response has been received to its end as far as the chunk framing tells. */
	public function isBodyComplete(): bool
	{
		return $this->chunkedBody?->isComplete() ?? true;
	}

	public function createFromString(string $response): Response
	{
		$headers = HttpHeaders::createFromString($response);
		$body = $this->createBody();

		$isChunked = strtolower($headers->get('Transfer-Encoding') ?? '') === 'chunked';
		if ($isChunked)
		{
			$body = new DechunkStream($body);
		}

		if (strtolower($headers->get('Content-Encoding') ?? '') === 'gzip')
		{
			$body = new BoundedInflateStream($body, $this->bodyLengthMax);
		}

		$this->chunkedBody = $isChunked ? new ChunkedFramingStream($body) : null;
		$body = $this->chunkedBody ?? $body;

		return new Response($headers->getStatus(), $headers->getHeaders(), $body, $headers->getVersion(), $headers->getReasonPhrase());
	}
}
