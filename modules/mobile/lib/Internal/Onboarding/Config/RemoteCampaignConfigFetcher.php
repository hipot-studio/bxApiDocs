<?php

namespace Bitrix\Mobile\Internal\Onboarding\Config;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;

/**
 * Fills the Option cache that JsonCampaignConfigProvider reads with the campaign
 * document published by the remote admin service. Kept apart from the provider so
 * the provider stays an autonomous cache reader; how often it runs (agent, event)
 * is decided outside the engine.
 *
 * Fail-safe by contract: a transport, status or schema failure returns an
 * unsuccessful Result and leaves the cached document untouched, so the series keeps
 * running on the previous document or on DefaultCampaignConfig.
 *
 * Endpoint contract, checked before the request rather than merely documented:
 * getConfigUrl() must return an absolute https URL answering 200 with the campaign
 * document of the campaign-config schema (sections
 * meta/schedule/steps/paths/industries/content/finals/analytics). The service is
 * not published yet, so CONFIG_URL is deliberately empty: while it stays empty
 * fetch() makes no request and reports 'endpoint is not configured'. Wiring the
 * real service means filling CONFIG_URL only - nothing else in the engine changes.
 */
class RemoteCampaignConfigFetcher
{
	protected const CONFIG_URL = '';

	private const REQUIRED_SCHEME = 'https';

	private const SOCKET_TIMEOUT = 2;
	private const STREAM_TIMEOUT = 2;

	/**
	 * The body is decoded on the way in and then on every agent pass that reads the
	 * cache, so an oversized answer is rejected before Json::decode() ever sees it.
	 * The shipped document is about 30 KB.
	 *
	 * The limit is given to every client the fetcher uses as bodyLengthMax, the injected
	 * one included, so reading stops at it instead of pulling an unbounded answer into
	 * memory first; the check below stays as the second barrier.
	 */
	private const MAX_BODY_BYTES = 512 * 1024;

	public function __construct(private ?HttpClient $httpClient = null)
	{
	}

	public function fetch(): Result
	{
		$result = new Result();
		$url = $this->getConfigUrl();
		if ($url === '')
		{
			return $result->addError(new Error('Campaign config endpoint is not configured'));
		}

		if (!$this->isAllowedEndpoint($url))
		{
			$this->logFailure('endpoint is not an absolute ' . self::REQUIRED_SCHEME . ' URL');

			return $result->addError(new Error('Campaign config endpoint is not an absolute https URL'));
		}

		$raw = $this->requestDocument($url);
		if ($raw === null)
		{
			return $result->addError(new Error('Campaign config request failed'));
		}

		// The bound is enforced twice, and neither is redundant. The handler of the client
		// breaks the read at the limit, so a real oversized answer never gets here - it arrives
		// as a transport error above. This one holds for a body that reached the code some other
		// way: requestDocument() is a seam, and what comes through it is not measured by anyone.
		if (strlen($raw) > self::MAX_BODY_BYTES)
		{
			$this->logFailure('response body exceeds ' . self::MAX_BODY_BYTES . ' bytes');

			return $result->addError(new Error('Campaign config response is too large'));
		}

		$document = $this->decodeDocument($raw);
		if ($document === null || !JsonCampaignConfigProvider::isUsableDocument($document))
		{
			return $result->addError(new Error('Campaign config response is not a campaign document'));
		}

		if (!$this->isNewerRevision($document))
		{
			return $result->addError(new Error('Campaign config response is not newer than the applied manifest'));
		}

		try
		{
			$this->storeDocument($raw);
		}
		catch (\Throwable $exception)
		{
			return $result->addError(new Error('Campaign config cache write failed: ' . $exception->getMessage()));
		}

		return $result;
	}

	protected function getConfigUrl(): string
	{
		return static::CONFIG_URL;
	}

	protected function requestDocument(string $url): ?string
	{
		$httpClient = $this->getHttpClient();
		$body = $httpClient->get($url);

		if ($body === false)
		{
			// setBodyLengthMax() makes the handler break the read, so a response over the bound
			// arrives here and not as a size of its own: the socket handler says so in its
			// message, the curl one says nothing at all
			$this->logFailure(
				'transport error (a body over ' . self::MAX_BODY_BYTES . ' bytes is reported here too): '
				. implode('; ', $httpClient->getError()),
			);

			return null;
		}

		if ($httpClient->getStatus() !== 200)
		{
			$this->logFailure('unexpected status ' . $httpClient->getStatus());

			return null;
		}

		if (!$this->isSameEndpoint($url, (string)$httpClient->getEffectiveUrl()))
		{
			$this->logFailure('redirected off the configured endpoint host');

			return null;
		}

		return $body;
	}

	/**
	 * Only an absolute https endpoint is requested: a plain-http or relative URL is
	 * refused before the client is touched.
	 */
	private function isAllowedEndpoint(string $url): bool
	{
		$uri = new Uri($url);

		return $uri->getScheme() === self::REQUIRED_SCHEME && $uri->getHost() !== '';
	}

	/**
	 * Redirects stay enabled - the service may normalize its own path - but the answer is
	 * accepted only from the configured host. A Location to anywhere else is exactly the
	 * step an SSRF would take once the endpoint becomes configurable.
	 */
	private function isSameEndpoint(string $requestedUrl, string $effectiveUrl): bool
	{
		if ($effectiveUrl === '' || !$this->isAllowedEndpoint($effectiveUrl))
		{
			return false;
		}

		$requested = new Uri($requestedUrl);
		$effective = new Uri($effectiveUrl);

		return strcasecmp($requested->getHost(), $effective->getHost()) === 0
			&& $requested->getPort() === $effective->getPort()
		;
	}

	protected function storeDocument(string $raw): void
	{
		Option::set(JsonCampaignConfigProvider::MODULE_ID, JsonCampaignConfigProvider::OPTION_NAME, $raw);
	}

	/**
	 * The answer as a document, or null when it is not a JSON object at all.
	 *
	 * A document is stored only when the reader would find it usable - its default path must
	 * resolve against the steps and push types declared in code, and its meta must name a
	 * format this engine implements. Caching a document that JsonCampaignConfigProvider would
	 * reject buys nothing: the series would keep running on the bundled manifest while the
	 * cache pretends to be current.
	 *
	 * @return array<string, mixed>|null
	 */
	private function decodeDocument(string $raw): ?array
	{
		try
		{
			$decoded = Json::decode($raw);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Whether the published revision of the campaign is above the applied one.
	 *
	 * The revision is monotonic, and this is the only place it is enforced: a document whose
	 * revision is not above the applied one is a rollback - a stale copy served by the admin
	 * service, the loser of a race between two deliveries - and storing it would replace a
	 * working campaign with an older one, silently and for every portal that fetched it.
	 *
	 * Compared in version order rather than as strings: release `.10` of a day follows `.9`,
	 * and a character-by-character comparison would call that a rollback.
	 *
	 * @param array<string, mixed> $document
	 */
	private function isNewerRevision(array $document): bool
	{
		$applied = $this->getAppliedRevision();
		if ($applied === null)
		{
			return true;
		}

		$published = JsonCampaignConfigProvider::readRevision(
			is_array($document['meta'] ?? null) ? $document['meta'] : [],
		);
		if ($published === null)
		{
			// unreachable through fetch(): acceptance already refuses a document without a
			// readable revision. A guard for whoever calls this with a document of their own.
			return false;
		}

		if (version_compare($published, $applied, '>'))
		{
			return true;
		}

		$this->logFailure("revision {$published} is not above the applied {$applied}");

		return false;
	}

	/**
	 * Revision of the campaign the portal runs on right now. A seam: a test states it instead
	 * of reading the option of the stand.
	 */
	protected function getAppliedRevision(): ?string
	{
		return (new JsonCampaignConfigProvider())->getAppliedRevision();
	}

	protected function getHttpClient(): HttpClient
	{
		$this->httpClient ??= new HttpClient([
			'socketTimeout' => self::SOCKET_TIMEOUT,
			'streamTimeout' => self::STREAM_TIMEOUT,
			'privateIp' => false,
		]);

		// a client handed in from outside carries options of its own, and both of these have to
		// hold for it too: reading must stop at the limit rather than be measured after the
		// fact, and the endpoint of a campaign document is never on the private network
		$this->httpClient->setBodyLengthMax(self::MAX_BODY_BYTES);
		$this->httpClient->setPrivateIp(false);

		return $this->httpClient;
	}

	protected function logFailure(string $reason): void
	{
		AddMessage2Log('Onboarding campaign config: fetch failed: ' . $reason, 'mobile');
	}
}
