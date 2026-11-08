<?php

namespace Bitrix\BIConnector\Internal\PortalHealthcheck;

final class Snapshot
{
	private readonly array $data;

	public function __construct(array $data)
	{
		$safe = array_intersect_key($data, array_flip([
			'status', 'diagnosticId', 'proxy', 'nodes', 'nodesCheck', 'nextAllowedAt',
			'resultDeadline', 'errorCode', 'retryAfter', 'checkedAt',
		]));
		if (isset($safe['proxy']))
		{
			$safe['proxy'] = self::safeCheck($safe['proxy']);
		}
		if (isset($safe['nodes']))
		{
			$safe['nodes'] = array_map(self::safeCheck(...), $safe['nodes']);
		}
		if (isset($safe['nodesCheck']))
		{
			$safe['nodesCheck'] = array_intersect_key(
				$safe['nodesCheck'],
				array_flip(['status', 'code']),
			);
		}
		$this->data = $safe;
	}

	public function toArray(): array
	{
		return $this->data;
	}

	private static function safeCheck(array $check): array
	{
		$safe = array_intersect_key($check, array_flip([
			'address', 'egressAddresses', 'node', 'publicHost', 'status', 'httpCode', 'remoteIp',
			'latencyMs', 'errors',
		]));
		if (isset($safe['errors']))
		{
			$safe['errors'] = array_map(
				static fn(array $error): array => array_intersect_key($error, ['code' => true, 'message' => true]),
				$safe['errors'],
			);
		}

		return $safe;
	}
}
