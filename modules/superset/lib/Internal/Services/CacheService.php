<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Superset\Internal\Api\Cache;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;

final class CacheService extends AbstractSupersetContext
{
	private const ANSWER_LOG_LIMIT = 1000;

	public function invalidate(): Main\Result
	{
		$api = new Cache($this->connector);
		$requestResult = $api->clearAll();

		if (!$requestResult->isSuccess())
		{
			return $this->createRequestErrorResult($requestResult, 'Cache invalidation');
		}

		if (
			$requestResult->getHttpStatus() !== HttpStatus::OK
			&& $requestResult->getHttpStatus() !== HttpStatus::NO_CONTENT
		)
		{
			return $this->createErrorResult(
				'Unexpected response when invalidating cache',
				$requestResult,
			);
		}

		$result = new Main\Result();
		$result->setData(['status' => 'ok']);

		return $result;
	}

	/**
	 * Narrow reset: only the permission cache of this portal, not the data caches of the instance.
	 *
	 * Timeouts are the business of the caller: this term carries no data at all and is sent from a background
	 * pass, so a caller has reason not to wait for it as long as a request for data waits.
	 */
	public function invalidatePermissions(?int $socketTimeout = null, ?int $streamTimeout = null): Main\Result
	{
		$connector = $this->resolveConnector($socketTimeout, $streamTimeout);
		$portalUrlResult = $this->resolvePortalUrl($connector);
		if (!$portalUrlResult->isSuccess())
		{
			return $portalUrlResult;
		}

		$api = new Cache($connector);
		$requestResult = $api->clearPermissions($portalUrlResult->getData()['portalUrl']);

		if (!$requestResult->isSuccess())
		{
			return $this->createRequestErrorResult($requestResult, 'Permission cache invalidation');
		}

		if (
			$requestResult->getHttpStatus() !== HttpStatus::OK
			&& $requestResult->getHttpStatus() !== HttpStatus::NO_CONTENT
		)
		{
			return $this->createErrorResult(
				'Unexpected response when invalidating permission cache',
				$requestResult,
			);
		}

		$answer = self::trimAnswer($requestResult->getAnswer());

		$result = new Main\Result();
		// The answer travels on whole and is not read here. In a box this endpoint lives in the installation
		// of the client, so its own log and metrics are reachable only by asking that client: what the answer
		// carries - the number of deleted keys among the rest - is observable on the portal or nowhere.
		$result->setData(['status' => 'ok', 'answer' => $answer]);

		return $result;
	}

	/**
	 * The record wins while it has an address: refreshDomain writes it together with the connection property.
	 * A record that was never filled is answered with the property itself - the value the keys of the cache
	 * are built from. An address taken from the current hit would not do: an agent run from cron sees no https,
	 * and the instance answers a reset for another scheme with success and nothing deleted.
	 */
	private function resolvePortalUrl(SupersetInstance $connector): Main\Result
	{
		$portalUrl = (string)$this->server->getPortalUrl();
		if ($portalUrl === '')
		{
			$propertyResult = (new DatabaseService($this->server, $connector))->getConnectionProperty('bi.server_url');
			if (!$propertyResult->isSuccess())
			{
				return $propertyResult;
			}

			$portalUrl = $propertyResult->getData()['value'];
		}

		if ($portalUrl === '')
		{
			return $this->createErrorResult(
				'Portal address is known neither to the server record nor to the connection'
			);
		}

		$result = new Main\Result();
		$result->setData(['portalUrl' => $portalUrl]);

		return $result;
	}

	/**
	 * The body is stored, not parsed, so its size is the only thing that has to be kept in hand: a provider
	 * answering with a page of markup instead of the agreed JSON must not put that page into the log of every
	 * reset.
	 */
	private static function trimAnswer(string $answer): string
	{
		$answer = trim($answer);

		return mb_strlen($answer) > self::ANSWER_LOG_LIMIT
			? mb_substr($answer, 0, self::ANSWER_LOG_LIMIT) . '...'
			: $answer
		;
	}

	/**
	 * Timeouts asked for by the caller mean a connector of its own: the defaults live in the connector and are shared
	 * by every consumer of the module. Asking for none keeps the connector of the context - the one a test replaces.
	 *
	 * Built the same way as a short-lived connector of the license delivery (`SelfHostedLicenseService`).
	 */
	private function resolveConnector(?int $socketTimeout, ?int $streamTimeout): SupersetInstance
	{
		$options = [];
		if ($socketTimeout !== null)
		{
			$options['socketTimeout'] = $socketTimeout;
		}

		if ($streamTimeout !== null)
		{
			$options['streamTimeout'] = $streamTimeout;
		}

		return $options === [] ? $this->connector : new SupersetInstance($this->server, $options);
	}
}
