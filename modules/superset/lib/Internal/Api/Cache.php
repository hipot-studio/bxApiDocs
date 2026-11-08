<?php

namespace Bitrix\Superset\Internal\Api;

use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

class Cache
{
	private const CACHE_API_LINK = '/api/v1/bitrix_cache/clear_all';

	/**
	 * The address of the narrow reset. Kept as a constant of its own next to the wide one: this is the
	 * value the boxed half of the feature is checked against, and a divergence of it shows up only in
	 * production and only as a reset that never happened.
	 */
	private const PERMISSION_CACHE_API_LINK = '/api/v1/bitrix_cache/clear_permissions';

	private ?SupersetInstance $connector;

	public function __construct(SupersetInstance $connector)
	{
		$this->connector = $connector;
	}

	public function clearAll(): RequestResult
	{
		return $this->connector->post(self::CACHE_API_LINK);
	}

	/**
	 * Drops the permission cache keys of one portal, leaving the data caches of the instance alone.
	 *
	 * The portal address is not derived on the instance side: the key template is anchored to it, so the
	 * value has to arrive as it was written to the connection property.
	 */
	public function clearPermissions(string $portalUrl): RequestResult
	{
		return $this->connector->post(self::PERMISSION_CACHE_API_LINK, ['portal' => $portalUrl]);
	}
}
