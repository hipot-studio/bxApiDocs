<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync\SectionsStructure;
use Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync\SyncPaginationStructure;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

/**
 * The request of disk.sync.snapshot.list.
 *
 * Only the position is asked for. The size of a page and the sections of a card both have a
 * meaningful answer without the client naming one - the published page size and everything the
 * portal serves - while the position has none: a fetch continued from a position the portal guessed
 * would either repeat what the client already holds or skip what it does not.
 */
final class SnapshotListRequest extends StrictRequest
{
	/**
	 * Where the fetch stands. Opaque to the client: it is handed out by disk.sync.session.create and
	 * then by every page, and the client only ever gives back the last one it received.
	 */
	public ?string $snapshotToken = null;

	/**
	 * How many objects of the page are asked for. A value above the published ceiling is refused
	 * rather than trimmed - a client whose page was quietly shortened would take the shorter page for
	 * the ceiling and never learn the real one.
	 *
	 * A page carrying a section the portal counts as heavy is served at the heavy ceiling, which
	 * disk.sync.capabilities.get publishes as pageSizeMaxHeavy. That is a shorter page and not a
	 * refusal: the fetch goes on through hasMore either way.
	 */
	public ?int $limit = null;

	/**
	 * The sections of a card the page is asked for. A request that names none is served everything
	 * this portal fills, which is what disk.sync.capabilities.get publishes as available.
	 */
	public ?SectionsStructure $sections = null;

	private const PARAMETER_SNAPSHOT_TOKEN = 'snapshotToken';
	private const PARAMETER_LIMIT = 'limit';

	/**
	 * @throws RequestValidationException
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);

		self::assertSnapshotToken($request->snapshotToken);
		self::assertLimit($request->limit, new SyncLimits());

		return $request;
	}

	public function getSnapshotToken(): string
	{
		return (string)$this->snapshotToken;
	}

	public function getLimit(): int
	{
		return $this->limit ?? SyncPaginationStructure::DEFAULT_LIMIT;
	}

	public function getSections(): SectionsStructure
	{
		return $this->sections ?? SectionsStructure::createDefault();
	}

	/**
	 * @throws RequestValidationException
	 */
	private static function assertSnapshotToken(?string $value): void
	{
		if ($value === null || $value === '')
		{
			throw self::refusal(
				self::PARAMETER_SNAPSHOT_TOKEN,
				'DISK_SYNC_REQUEST_PARAMETER_REQUIRED',
				['#PARAMETER#' => self::PARAMETER_SNAPSHOT_TOKEN],
			);
		}
	}

	/**
	 * @throws RequestValidationException
	 */
	private static function assertLimit(?int $value, SyncLimits $limits): void
	{
		if ($value === null)
		{
			return;
		}

		if ($value < 1)
		{
			throw self::refusal(self::PARAMETER_LIMIT, 'DISK_SYNC_REQUEST_LIMIT_INVALID');
		}

		$max = $limits->getPageSizeMax();
		if ($value > $max)
		{
			throw self::refusal(
				self::PARAMETER_LIMIT,
				'DISK_SYNC_REQUEST_LIMIT_TOO_LARGE',
				['#MAX#' => (string)$max],
			);
		}
	}
}
