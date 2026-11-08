<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\StrictRequest;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * The page size of the synchronisation contour.
 *
 * The stock PaginationStructure trims a limit above its ceiling with min() and answers as if the
 * page the client asked for had been served. Here a value outside the published bounds is refused:
 * a client that asked for more than the portal serves has to learn the bound rather than infer it
 * from a page that came back shorter than requested.
 *
 * Offsets and page numbers are not part of the contour: its pages are keyset pages addressed by an
 * opaque position, so an offset here would be a parameter that cannot be honoured.
 */
final class SyncPaginationStructure extends Structure
{
	public const DEFAULT_LIMIT = 100;

	/**
	 * The published ceiling. The server is free to serve a shorter page; it never serves a longer
	 * one, and it never silently serves a shorter one than the ceiling allows without saying so.
	 */
	public const MAX_LIMIT = 100;

	private const PARAMETER_LIMIT = 'limit';

	private int $limit = self::DEFAULT_LIMIT;

	private function __construct()
	{
	}

	/**
	 * The pagination of a request that carries none.
	 */
	public static function createDefault(): self
	{
		return new self();
	}

	public static function create(mixed $value, ?string $dtoClass = null, ?Request $request = null): self
	{
		$structure = new self();

		if (!is_array($value))
		{
			throw StrictRequest::refusal('pagination', 'DISK_SYNC_REQUEST_PAGINATION_INVALID');
		}

		foreach (array_keys($value) as $name)
		{
			if ((string)$name !== self::PARAMETER_LIMIT)
			{
				throw StrictRequest::refusal(
					'pagination.' . $name,
					'DISK_SYNC_REQUEST_UNKNOWN_PARAMETER',
					['#PARAMETER#' => 'pagination.' . $name],
				);
			}
		}

		if (array_key_exists(self::PARAMETER_LIMIT, $value))
		{
			$structure->limit = self::readLimit($value[self::PARAMETER_LIMIT]);
		}

		return $structure;
	}

	public function getLimit(): int
	{
		return $this->limit;
	}

	private static function readLimit(mixed $value): int
	{
		if (!is_int($value) && !(is_string($value) && preg_match('#^\d+$#D', $value) === 1))
		{
			throw StrictRequest::refusal('pagination.limit', 'DISK_SYNC_REQUEST_LIMIT_INVALID');
		}

		$limit = (int)$value;

		if ($limit < 1)
		{
			throw StrictRequest::refusal('pagination.limit', 'DISK_SYNC_REQUEST_LIMIT_INVALID');
		}

		if ($limit > self::MAX_LIMIT)
		{
			throw StrictRequest::refusal(
				'pagination.limit',
				'DISK_SYNC_REQUEST_LIMIT_TOO_LARGE',
				['#MAX#' => (string)self::MAX_LIMIT],
			);
		}

		return $limit;
	}
}
