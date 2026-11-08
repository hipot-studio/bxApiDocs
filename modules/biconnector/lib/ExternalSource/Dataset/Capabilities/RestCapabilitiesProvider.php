<?php

namespace Bitrix\BIConnector\ExternalSource\Dataset\Capabilities;

use Bitrix\BIConnector\DataSourceConnector\Aggregation\CanonicalJson;
use Bitrix\BIConnector\DataSourceConnector\Aggregation\ExternalCapabilitiesDeclaration;
use Bitrix\BIConnector\ExternalSource\Source\Rest;
use Bitrix\BIConnector\ExternalSource\SourceManager;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Application;
use Bitrix\Main\Data\ManagedCache;
use Bitrix\Main\Error;
use Bitrix\Main\SystemException;

/**
 * The capabilities declaration of a REST application, cached on the portal side (API-01).
 *
 * The cache is mandatory rather than desirable: the capabilities fingerprint is asked for on every
 * data response, so an uncached declaration would cost a network call per response.
 *
 * Any failure - an unreachable address, a timeout, garbage in the answer, an unsuccessful status, a
 * body over the size limit - gives an empty declaration, which normalizes to
 * `Capabilities::unsupported()`, and is written to the event log of the portal. An unavailable
 * address has no right to break the row path.
 *
 * There are no invalidation hooks and none are needed: everything mutable the declaration depends on
 * is part of the cache key, so a changed address or changed credentials produce a different key
 * instead of a stale entry. A changed dataset does not concern the cache at all - what is cached is
 * the raw declaration of the application, while normalization runs against the current schema on
 * every call.
 */
final class RestCapabilitiesProvider
{
	private const DECLARATION_TTL = 3600;

	/**
	 * A failure is cached far shorter than a declaration: an unavailable address must not add a network
	 * call to every data response, but must not close the channel for an hour either.
	 */
	private const FAILURE_TTL = 60;

	private const CACHE_TABLE_ID = 'biconnector_rest_capabilities';
	private const CACHE_KEY_DECLARATION = 'biconnector_rest_capabilities_declaration_';
	private const CACHE_KEY_FAILURE = 'biconnector_rest_capabilities_failure_';

	/**
	 * @param ManagedCache $cache Cache of declarations and of failures.
	 * @param Rest|null $transport Transport of the connection. Built from the connection id when not
	 *     supplied, and only where the declaration is actually asked for: the transport resolves the
	 *     connection on construction, and an answer taken from the cache needs none of it.
	 * @param int $connectionId Id of the connection the declaration is asked of.
	 * @param string $urlCapabilities Capabilities address of the connector; part of the cache key.
	 * @param string $connectionFingerprint Fingerprint of the connection settings; part of the cache key.
	 */
	public function __construct(
		private readonly ManagedCache $cache,
		private readonly ?Rest $transport,
		private readonly int $connectionId,
		private readonly string $urlCapabilities,
		private readonly string $connectionFingerprint,
	)
	{
	}

	public static function createForConnection(int $connectionId, string $urlCapabilities): self
	{
		return new self(
			Application::getInstance()->getManagedCache(),
			null,
			$connectionId,
			$urlCapabilities,
			self::getConnectionFingerprint($connectionId),
		);
	}

	/**
	 * @param string $tableCode External table code, the same one the data address receives.
	 */
	public function getDeclaration(string $tableCode): ExternalCapabilitiesDeclaration
	{
		$signature = $this->getCacheSignature($tableCode);

		$declarationKey = self::CACHE_KEY_DECLARATION . $signature;
		if ($this->cache->read(self::DECLARATION_TTL, $declarationKey, self::CACHE_TABLE_ID))
		{
			return ExternalCapabilitiesDeclaration::fromArray($this->cache->get($declarationKey));
		}

		$failureKey = self::CACHE_KEY_FAILURE . $signature;
		if ($this->cache->read(self::FAILURE_TTL, $failureKey, self::CACHE_TABLE_ID))
		{
			return ExternalCapabilitiesDeclaration::createEmpty();
		}

		try
		{
			$declaration = $this->getTransport()->getCapabilitiesDeclaration($tableCode);
		}
		catch (SystemException $exception)
		{
			return $this->rememberFailure($failureKey, $tableCode, $exception->getMessage());
		}

		if ($declaration === null)
		{
			return $this->rememberFailure($failureKey, $tableCode, 'Capabilities declaration is not available');
		}

		$this->cache->set($declarationKey, $declaration);

		return ExternalCapabilitiesDeclaration::fromArray($declaration);
	}

	private function getTransport(): Rest
	{
		return $this->transport ?? new Rest($this->connectionId);
	}

	private function rememberFailure(
		string $failureKey,
		string $tableCode,
		string $message,
	): ExternalCapabilitiesDeclaration
	{
		$this->cache->set($failureKey, true);

		Logger::logErrors([new Error($message)], [
			'connectionType' => 'rest',
			'connectionId' => $this->connectionId,
			'requestedUrl' => $this->urlCapabilities,
			'table' => $tableCode,
		]);

		return ExternalCapabilitiesDeclaration::createEmpty();
	}

	/**
	 * Identity of the answer: the connection, the table it was asked about, and all the mutable state
	 * the answer depends on. A changed address or changed credentials give another key.
	 */
	private function getCacheSignature(string $tableCode): string
	{
		return hash('sha256', CanonicalJson::encode([
			'connectionId' => $this->connectionId,
			'table' => $tableCode,
			'url' => $this->urlCapabilities,
			'connection' => $this->connectionFingerprint,
		]));
	}

	/**
	 * Credentials never reach the cache key in the clear, and a changed value still gives another key.
	 *
	 * The settings are read by the id of the connection: the connection itself is not needed here, and
	 * reading it would cost a query on the path of every data response.
	 */
	private static function getConnectionFingerprint(int $connectionId): string
	{
		if ($connectionId <= 0)
		{
			return '';
		}

		$settings = [];
		foreach (SourceManager::getSourceSettingsBySourceId($connectionId) as $setting)
		{
			$settings[(string)$setting->getCode()] = (string)$setting->getValue();
		}

		return CanonicalJson::fingerprint($settings);
	}
}
