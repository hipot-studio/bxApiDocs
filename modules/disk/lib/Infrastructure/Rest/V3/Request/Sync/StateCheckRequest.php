<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync\SectionsStructure;
use Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync\SyncPaginationStructure;
use Bitrix\Disk\Internal\Service\Sync\Position\CheckToken;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

/**
 * The request of disk.sync.state.check, in either of its two modes.
 *
 * The mode is chosen by what the request carries and never by a parameter naming it: a list of
 * objects the client already holds, or the area a change of access named. Both modes ask one
 * question - "what is the state of this now" - and the parameters of one are meaningless to the
 * other, so a request carrying both is refused, and so is one carrying neither. Guessing a mode
 * would be the worst of the three answers: a client that meant to check its memory and had its list
 * dropped would be told about an area it never asked about, and would delete what it holds.
 *
 * How big a page may be is decided here, because this is the one object that holds both the list and
 * the sections: the checkers behind the method deliberately do not weigh sections, and the ceiling of
 * a page follows the cost of the sections asked for, exactly as it does for the fetch and the journal.
 * What differs between the modes is only what happens at the ceiling. The area is served a shorter
 * page and goes on through hasMore; a list has no continuation at all, so a list above the ceiling is
 * refused rather than cut - a client whose list was quietly trimmed would take the silence about the
 * missing objects for "you do not see them" and delete live files.
 */
final class StateCheckRequest extends StrictRequest
{
	/**
	 * The objects of the memory of the client, as its cards name them. The mode over a list.
	 *
	 * @var int[]|null
	 */
	public ?array $objectIds = null;

	/**
	 * The object the area is rooted at - the root a change of access named. The mode over an area.
	 * Answered from the second page on as well, where it has to name the same area as the token.
	 */
	public ?int $scopeRootId = null;

	/**
	 * Where the walk of the area stands. Absent from the first page - the portal builds the position
	 * of that page itself - and given back exactly as received on every page after it. It belongs to
	 * the pair of a user and an application, needs no session and touches none.
	 */
	public ?string $checkToken = null;

	/**
	 * How many objects of the area are asked for. Of the mode over an area alone: the list of the
	 * other mode is the page, and a page size beside it would promise a pagination it does not have.
	 *
	 * A value above the published ceiling is refused rather than trimmed. A page that asks for a
	 * section the portal counts as heavy is served at the heavy ceiling, which
	 * disk.sync.capabilities.get publishes as pageSizeMaxHeavy: a shorter page, not a refusal.
	 */
	public ?int $limit = null;

	/**
	 * The sections of a card the visible objects of the answer are described by. A request that names
	 * none is served everything this portal fills. An object the reader does not see carries no card
	 * whatever is asked for here.
	 */
	public ?SectionsStructure $sections = null;

	/**
	 * The sections whose assembly is expensive enough to halve a page, as
	 * {@see SyncLimits::getPageSizeMaxHeavy()} is published for. The same set the fetch and the
	 * journal weigh their pages by.
	 */
	private const HEAVY_SECTIONS = [SectionsStructure::SECTION_LOCATION];

	private const PARAMETER_OBJECT_IDS = 'objectIds';
	private const PARAMETER_SCOPE_ROOT_ID = 'scopeRootId';
	private const PARAMETER_CHECK_TOKEN = 'checkToken';
	private const PARAMETER_LIMIT = 'limit';

	/** @var int[] what the list mode was actually asked about: positive, in the order it was sent, once each */
	private array $objects = [];

	/**
	 * @throws RequestValidationException
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);

		$limits = new SyncLimits();

		self::assertCheckToken($request->checkToken);
		self::assertOneModeIsNamed($request);

		if ($request->isOverList())
		{
			$request->objects = self::readObjectIds((array)$request->objectIds);
			self::assertListFits($request->objects, $request->pageCeiling($limits));
			self::assertNoPageSize($request->limit);
		}
		else
		{
			self::assertScopeRootId($request->scopeRootId, $request->checkToken);
			self::assertLimit($request->limit, $limits);
		}

		return $request;
	}

	public function isOverList(): bool
	{
		return $this->objectIds !== null;
	}

	/**
	 * @return int[]
	 */
	public function getObjectIds(): array
	{
		return $this->objects;
	}

	/**
	 * Null on every page but the first, where the area is named by the token instead.
	 */
	public function getScopeRootId(): ?int
	{
		return $this->scopeRootId;
	}

	public function getCheckToken(): ?string
	{
		return $this->checkToken;
	}

	/**
	 * Already at the ceiling the sections of this request allow: what a client may ask for is one
	 * thing, what a page of these sections costs is another.
	 */
	public function getLimit(): int
	{
		return min($this->limit ?? SyncPaginationStructure::DEFAULT_LIMIT, $this->pageCeiling(new SyncLimits()));
	}

	public function getSections(): SectionsStructure
	{
		return $this->sections ?? SectionsStructure::createDefault();
	}

	/**
	 * A request that carries both a token and a root has to name one area by them.
	 *
	 * The token is the authority on the area it was issued for, so the two disagreeing is a mistake of
	 * the client rather than a choice for the portal to make: continuing the walk of the token would
	 * answer about an area the client did not ask about, and starting a new walk from the root would
	 * lose the page the client stood at. The check belongs here with every other refusal of this
	 * request and runs where the token can be read - only the method holds the pair that decodes it.
	 *
	 * @throws RequestValidationException
	 */
	public function assertNamesTheAreaOf(CheckToken $token): void
	{
		if ($this->scopeRootId !== null && $this->scopeRootId !== $token->getScopeRootId())
		{
			throw self::refusal(self::PARAMETER_SCOPE_ROOT_ID, 'DISK_SYNC_REQUEST_CHECK_TOKEN_OTHER_SCOPE');
		}
	}

	/**
	 * The ceiling of a page of these sections. The list of the other mode is bounded by the same
	 * number: one method with two ceilings for one set of sections would be a contract nobody could
	 * hold in their head.
	 */
	private function pageCeiling(SyncLimits $limits): int
	{
		$sections = $this->getSections();
		foreach (self::HEAVY_SECTIONS as $section)
		{
			if ($sections->has($section))
			{
				return $limits->getPageSizeMaxHeavy();
			}
		}

		return $limits->getPageSizeMax();
	}

	/**
	 * A token is absent on the first page and opaque on every page after it, and the empty string is
	 * neither. Left to the method it would be decoded, fail to decode and come back as a position that
	 * cannot be continued - sending the client to build its session and its walk anew over what is
	 * simply a parameter it filled in wrongly.
	 *
	 * @throws RequestValidationException
	 */
	private static function assertCheckToken(?string $value): void
	{
		if ($value === '')
		{
			throw self::refusal(
				self::PARAMETER_CHECK_TOKEN,
				'DISK_SYNC_REQUEST_PARAMETER_REQUIRED',
				['#PARAMETER#' => self::PARAMETER_CHECK_TOKEN],
			);
		}
	}

	/**
	 * The refusal is addressed to the list, in both directions: it is the parameter that tells the
	 * two modes apart, and the phrase names the other one.
	 *
	 * @throws RequestValidationException
	 */
	private static function assertOneModeIsNamed(self $request): void
	{
		$namesArea = $request->scopeRootId !== null || $request->checkToken !== null;

		if ($request->isOverList() && $namesArea)
		{
			throw self::refusal(self::PARAMETER_OBJECT_IDS, 'DISK_SYNC_REQUEST_CHECK_MODE_AMBIGUOUS');
		}

		if (!$request->isOverList() && !$namesArea)
		{
			throw self::refusal(self::PARAMETER_OBJECT_IDS, 'DISK_SYNC_REQUEST_CHECK_MODE_MISSING');
		}
	}

	/**
	 * @param array<int|string, mixed> $value
	 * @return int[]
	 * @throws RequestValidationException
	 */
	private static function readObjectIds(array $value): array
	{
		if ($value === [] || !array_is_list($value))
		{
			throw self::refusal(self::PARAMETER_OBJECT_IDS, 'DISK_SYNC_REQUEST_OBJECT_IDS_INVALID');
		}

		$objectIds = [];
		foreach ($value as $objectId)
		{
			if (!is_int($objectId) && !(is_string($objectId) && preg_match('#^\d+$#D', $objectId) === 1))
			{
				throw self::refusal(self::PARAMETER_OBJECT_IDS, 'DISK_SYNC_REQUEST_OBJECT_IDS_INVALID');
			}

			$objectId = (int)$objectId;
			if ($objectId < 1)
			{
				throw self::refusal(self::PARAMETER_OBJECT_IDS, 'DISK_SYNC_REQUEST_OBJECT_IDS_INVALID');
			}

			// One answer per object and not per mention: an object named twice is one object, and the
			// check answers about objects.
			$objectIds[$objectId] = $objectId;
		}

		return array_values($objectIds);
	}

	/**
	 * @param int[] $objectIds
	 * @throws RequestValidationException
	 */
	private static function assertListFits(array $objectIds, int $ceiling): void
	{
		if (count($objectIds) > $ceiling)
		{
			throw self::refusal(
				self::PARAMETER_OBJECT_IDS,
				'DISK_SYNC_REQUEST_OBJECT_IDS_TOO_MANY',
				['#MAX#' => (string)$ceiling],
			);
		}
	}

	/**
	 * @throws RequestValidationException
	 */
	private static function assertNoPageSize(?int $value): void
	{
		if ($value !== null)
		{
			throw self::refusal(self::PARAMETER_LIMIT, 'DISK_SYNC_REQUEST_LIMIT_NOT_APPLICABLE');
		}
	}

	/**
	 * The root is required to open a walk and optional to continue one, because a token already
	 * carries the area it was issued for. Sent along with a token it is checked against that area by
	 * the method, which is the only place the token can be read.
	 *
	 * @throws RequestValidationException
	 */
	private static function assertScopeRootId(?int $value, ?string $checkToken): void
	{
		if ($value === null && $checkToken !== null)
		{
			return;
		}

		if ($value === null || $value < 1)
		{
			throw self::refusal(self::PARAMETER_SCOPE_ROOT_ID, 'DISK_SYNC_REQUEST_SCOPE_ROOT_INVALID');
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
