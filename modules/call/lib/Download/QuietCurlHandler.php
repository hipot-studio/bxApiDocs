<?php

namespace Bitrix\Call\Download;

use Bitrix\Main\Web\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

/**
 * The curl handler of main that has a logger before it is built: the parent logs the request from
 * its constructor, and without a logger of its own it would fetch main.HttpClient for that.
 *
 * @internal Built by {@see PolicyHttpClient} only.
 */
final class QuietCurlHandler extends Http\Curl\Handler
{
	public function __construct(RequestInterface $request, Http\ResponseBuilderInterface $responseBuilder, array $options = [])
	{
		$this->setLogger(new NullLogger());

		parent::__construct($request, $responseBuilder, $options);
	}
}
