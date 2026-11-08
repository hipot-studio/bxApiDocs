<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync\SectionsStructure;
use Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync\SyncPaginationStructure;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

/**
 * The request of disk.sync.changes.list.
 *
 * Only the position is asked for, exactly as in the request of the initial fetch: the size of a page
 * and the sections of a card both have a meaningful answer without the client naming one, while the
 * position has none - a journal continued from a position the portal guessed would either replay
 * what the client already applied or step over what it never saw.
 *
 * How far down the tail of the journal the reading may go is not a parameter at all. The safe window
 * is applied by the server (see SafeWindow), and a client able to name its own boundary could ask
 * for records that are still on their way in and would then never be handed them again.
 */
final class ChangesListRequest extends StrictRequest
{
	/**
	 * Where the reading of the journal stands. Opaque to the client: it is handed out by
	 * disk.sync.session.create and then by every page, and the client only ever gives back the last
	 * one it received. It outlives the session it came with - it belongs to the pair of a user and an
	 * application - so a new session does not invalidate it.
	 */
	public ?string $cursor = null;

	/**
	 * How many records of the page are asked for. A value above the published ceiling is refused
	 * rather than trimmed - a client whose page was quietly shortened would take the shorter page for
	 * the ceiling and never learn the real one.
	 *
	 * A page carrying a section the portal counts as heavy is served at the heavy ceiling, which
	 * disk.sync.capabilities.get publishes as pageSizeMaxHeavy. That is a shorter page and not a
	 * refusal: the journal goes on through hasMore either way.
	 */
	public ?int $limit = null;

	/**
	 * The sections of a card the records of the page are asked for. A request that names none is
	 * served everything this portal fills, which is what disk.sync.capabilities.get publishes as
	 * available. A tombstone and a change of access carry no card at all, so the sections say nothing
	 * about them.
	 */
	public ?SectionsStructure $sections = null;

	private const PARAMETER_CURSOR = 'cursor';
	private const PARAMETER_LIMIT = 'limit';

	/**
	 * @throws RequestValidationException
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);

		self::assertCursor($request->cursor);
		self::assertLimit($request->limit, new SyncLimits());

		return $request;
	}

	public function getCursor(): string
	{
		return (string)$this->cursor;
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
	private static function assertCursor(?string $value): void
	{
		if ($value === null || $value === '')
		{
			throw self::refusal(
				self::PARAMETER_CURSOR,
				'DISK_SYNC_REQUEST_PARAMETER_REQUIRED',
				['#PARAMETER#' => self::PARAMETER_CURSOR],
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
