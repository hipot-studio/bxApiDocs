<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Main\Web\Json;

/** @internal Server-only request snapshot; never part of an editor configuration. */
final class SharedOpenRequest
{
	public const CONTEXT_KEY = 'openRequestBodies';
	public const MAX_BODY_BYTES = 16_384;
	public const MAX_CONTEXT_BYTES = 60_000;
	public const MAX_ATTEMPTS = 64;

	public static function isValid(array $body): bool
	{
		return self::hasOnly($body, ['user', 'grant', 'document', 'editor', 'host_open_id', 'client'])
			&& self::hasOnly($body['user'] ?? null, ['id', 'name'])
			&& self::hasOnly($body['grant'] ?? null, ['mode', 'expires_at'])
			&& self::hasOnly($body['document'] ?? null, ['title', 'permissions', 'url', 'filetype'])
			&& self::hasOnly($body['document']['permissions'] ?? null, ['chat', 'download', 'print'])
			&& self::hasOnly($body['editor'] ?? null, ['lang', 'ui'])
			&& self::hasOnly($body['editor']['ui'] ?? null, ['shell', 'mode'])
			&& self::hasOnly($body['client'] ?? [], ['origin'])
			&& is_string($body['host_open_id'] ?? null)
			&& is_string($body['user']['id'] ?? null)
			&& ($body['grant']['mode'] ?? null) === 'edit'
			&& strlen(Json::encode($body)) <= self::MAX_BODY_BYTES;
	}

	private static function hasOnly(mixed $value, array $keys): bool
	{
		return is_array($value) && array_diff(array_keys($value), $keys) === [];
	}
}
