<?php

namespace Bitrix\Call\Download;

use Bitrix\Main\Web\Http;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\IpAddress;
use Bitrix\Main\Web\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The client of a {@see DownloadPolicy} that states what a plain HttpClient cannot do.
 *
 * Quiet: the client writes nothing anywhere a url could end up - no line to the logger
 * main.HttpClient, no verbose log of curl, no PHP warning. The url of a download may carry a
 * signature, and all three quote it whole. A quiet client takes no logger from outside either: a
 * handler of OnHttpClientBuildRequest may hand one to every client of the portal (signsafe's
 * out-proxy does), and the socket handler logs to whatever logger the client holds when it is
 * built. What such a handler does to the request itself is kept.
 *
 * Private addresses refused apart from names that do not resolve: HttpClient resolves the host
 * with gethostbyname(), which answers a failure with the name itself, and then refuses that name
 * as a private address - so a passing DNS failure would read as a verdict that never changes.
 *
 * @internal Built by {@see DownloadPolicy::createHttpClient()} only.
 */
final class PolicyHttpClient extends HttpClient
{
	public function __construct(array $options, private readonly bool $quiet)
	{
		parent::__construct($options);

		if ($this->quiet)
		{
			$this->logger = new NullLogger();
		}
	}

	/**
	 * A quiet client keeps the logger that says nothing, whoever hands it another - above all a
	 * handler of OnHttpClientBuildRequest, which runs after the constructor and before the handler
	 * is built.
	 */
	public function setLogger(LoggerInterface $logger): void
	{
		if (!$this->quiet)
		{
			parent::setLogger($logger);
		}
	}

	/**
	 * The handler of the parent, except that a quiet client builds its curl handler with no logger
	 * to find: the curl handler of main logs the request while it is being constructed, before the
	 * parent hands it the logger of the client, and so falls back to main.HttpClient. The options
	 * are the ones the parent passes (HttpClient::createHandler() of main 26.x) except the verbose
	 * log of curl, which a quiet client never has. The socket handler takes the logger before its
	 * first line and has no verbose log, so it is left to the parent.
	 */
	protected function createHandler(RequestInterface $request, bool $async = false)
	{
		if (!$this->quiet || !$this->useCurl)
		{
			return parent::createHandler($request, $async);
		}

		if ($this->sslVerify === false)
		{
			$this->contextOptions['ssl']['verify_peer_name'] = false;
			$this->contextOptions['ssl']['verify_peer'] = false;
			$this->contextOptions['ssl']['allow_self_signed'] = true;
		}
		if (!isset($this->responseBuilder))
		{
			$this->responseBuilder = new Http\ResponseBuilder();
		}

		$handler = new QuietCurlHandler($request, $this->responseBuilder, [
			'waitResponse' => $this->waitResponse,
			'bodyLengthMax' => $this->bodyLengthMax,
			'proxyHost' => $this->proxyHost,
			'proxyPort' => $this->proxyPort,
			'effectiveIp' => $this->effectiveIp,
			'contextOptions' => $this->contextOptions,
			'socketTimeout' => $this->socketTimeout,
			'streamTimeout' => $this->streamTimeout,
			'async' => $async,
			'curlLogFile' => null,
		]);

		if ($this->shouldFetchBody !== null)
		{
			$handler->shouldFetchBody($this->shouldFetchBody);
		}

		return $handler;
	}

	/**
	 * The checks of the parent, but the host is resolved here, once: a name that does not resolve
	 * is a network error (the engine retries it), only a resolved private address is PRIVATE_IP.
	 * The address is pinned for the connection as the parent pins it.
	 */
	protected function checkRequest(RequestInterface $request): bool
	{
		if ($this->privateIp)
		{
			return parent::checkRequest($request);
		}

		// The parent checks the scheme, the host and its punycode; it would also resolve the host.
		$this->privateIp = true;
		try
		{
			$valid = parent::checkRequest($request);
		}
		finally
		{
			$this->privateIp = false;
		}
		if (!$valid)
		{
			return false;
		}

		$uri = new Uri((string)$request->getUri());
		$uri->convertToPunycode();
		$host = $uri->getHost();

		$ip = IpAddress::createByName($host);
		// An IPv6 literal is not a name: gethostbyname() cannot take it, and the parent refuses it.
		if (filter_var($ip->get(), FILTER_VALIDATE_IP) === false && !str_starts_with($host, '['))
		{
			// The host is a part of the url, which diagnostics may be forbidden to name.
			$this->addError('NETWORK', 'The host name does not resolve');

			return false;
		}
		if ($ip->isPrivate())
		{
			$this->addError('PRIVATE_IP', "Resolved IP is incorrect or private: {$ip->get()}");

			return false;
		}

		$this->effectiveIp = $ip;

		return true;
	}

	/**
	 * A quiet client keeps its errors to itself: running out of redirects is otherwise also raised
	 * as a PHP warning that quotes the whole url.
	 */
	protected function addError($code, $message, $triggerWarning = false)
	{
		parent::addError($code, $message, $triggerWarning && !$this->quiet);
	}
}
