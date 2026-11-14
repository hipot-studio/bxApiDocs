<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Requisite;

use Bitrix\Crm\BankDetailTable;
use Bitrix\Crm\V2\Internal\Access\Requisite\EntityLinkAccess;
use Bitrix\Crm\V2\Internal\Repository\Requisite\EntityLinkRepository;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Crm\V2\Public\Provider\Item\AccessMode;
use Bitrix\Crm\V2\Public\Provider\Item\ItemProvider;
use Bitrix\Main;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Reading and setting a requisite pair chosen in a CRM entity - a requisite and the bank requisite
 * beside it. Whose pair is served is the descendant's to say: a group brings the columns its pair is
 * kept in, the write of the repository, the value class it answers with, the key of its own data,
 * its own refusals and the rule of which requisite may be chosen.
 *
 * The subject of every permission check is the user given to the constructor and nobody else: the
 * scenario never asks who the current user is.
 *
 * Both halves keep the same shape. The entity that owns the choice is checked first - the type has to
 * be one the link serves, the entity has to exist, and the user has to be allowed to read it for a
 * read and to change it for a write. All three answer {@see self::ownerNotFoundError()}, so a user
 * who may not see an entity cannot tell it apart from one that is not there. The pair itself is
 * checked next: a requisite has to be one the group allows ({@see self::isRequisiteAllowed()}), and a
 * bank requisite one of that requisite's - the latter rule is the same for every group.
 *
 * A read never refuses over the pair. An entity with no row at all and an entity whose row names no
 * requisite both answer with an empty pair, and so does a stored pair the group keeps out of sight
 * ({@see self::isStoredPairVisible()}) - a refusal there would tell about the party the pair belongs
 * to.
 *
 * Both identifiers arrive normalised as non-negative integers: the request layer turns a missing
 * value and `null` into zero, and the scenario does not tell the two apart. A negative identifier
 * breaks that contract instead of asking for a refusal, and the write of the repository throws on it.
 *
 * The sets of refusals of the groups are separate types whose cases carry the same names, and no
 * common type says so: each group keeps its own `final` enum in the public contract of V2, and a
 * shared one there would publish a type no consumer of that contract asked for. A refusal therefore
 * reaches this class as the {@see Error} a group built out of a case of its own.
 *
 * {@see EntityLinkConsistencyChecker::check()} belongs to the legacy write path and is not called in
 * these scenarios: it throws refusals with numeric codes of its own, while the checks above are the
 * whole rule and every refusal is a case of the group.
 *
 * @template TEntity of object the value class of the group
 *
 * @internal
 */
abstract class AbstractOwnerRequisitePairService
{
	protected readonly EntityLinkService $linkService;

	protected readonly EntityLinkRepository $linkRepository;

	protected readonly EntityClientSellerResolver $clientSellerResolver;

	protected readonly EntityLinkAccess $access;

	public function __construct(
		protected readonly int $userId,
		?EntityLinkService $linkService = null,
		?EntityLinkRepository $linkRepository = null,
		?EntityClientSellerResolver $clientSellerResolver = null,
		?EntityLinkAccess $access = null,
	)
	{
		$this->linkService = $linkService ?? EntityLinkService::getInstance();
		$this->linkRepository = $linkRepository ?? new EntityLinkRepository();
		$this->clientSellerResolver = $clientSellerResolver ?? new EntityClientSellerResolver();
		$this->access = $access ?? new EntityLinkAccess($userId);
	}

	/** Result data key of both {@see self::get()} and {@see self::set()}. */
	abstract protected function resultDataKey(): string;

	/** The column the chosen requisite of the group is stored in. */
	abstract protected function requisiteIdColumn(): string;

	/** The column the chosen bank requisite of the group is stored in. */
	abstract protected function bankRequisiteIdColumn(): string;

	/**
	 * The write of the group, the pair as a whole.
	 *
	 * @throws Main\ArgumentException on a negative identifier
	 * @throws Main\DB\SqlQueryException
	 */
	abstract protected function writePair(
		EntityType $ownerType,
		int $ownerId,
		int $requisiteId,
		int $bankRequisiteId,
	): void;

	/**
	 * The pair of the group, where `null` means nothing is chosen.
	 *
	 * @return TEntity
	 */
	abstract protected function newPair(?int $requisiteId, ?int $bankRequisiteId): object;

	/** The refusal the group reports for an owner that is not there, not of the right type or not visible. */
	abstract protected function ownerNotFoundError(): Error;

	/** The refusal the group reports for a requisite it does not allow. */
	abstract protected function requisiteNotAllowedError(): Error;

	/** The refusal the group reports for a bank requisite it does not allow. */
	abstract protected function bankRequisiteNotAllowedError(): Error;

	/**
	 * Whether the requisite may be chosen in this entity - the rule of the party the pair belongs to,
	 * and the only question of a write this class leaves open. Asked for a requisite that is being
	 * chosen and never while the choice is being cleared.
	 */
	abstract protected function isRequisiteAllowed(EntityType $ownerType, int $ownerId, int $requisiteId): bool;

	/**
	 * Whether a stored requisite is answered to this user at all. The stored pair is not verified
	 * against the entity as it stands today - this asks about the party the pair belongs to, which is
	 * a question only some of the groups have. Asked for a stored requisite and never for an entity
	 * that has chosen nothing.
	 */
	protected function isStoredPairVisible(EntityType $ownerType, int $ownerId): bool
	{
		return true;
	}

	/** @return Result data: the key of {@see self::resultDataKey()} => TEntity */
	public function get(EntityType $ownerType, int $ownerId): Result
	{
		if (!$this->isApplicableType($ownerType))
		{
			return $this->fail($this->ownerNotFoundError());
		}

		if (!$this->ownerExists($ownerType, $ownerId))
		{
			return $this->fail($this->ownerNotFoundError());
		}

		if (!$this->access->canReadOwnerEntity($ownerType->getId(), $ownerId))
		{
			return $this->fail($this->ownerNotFoundError());
		}

		$row = $this->readPair($ownerType, $ownerId);
		$requisiteId = (int)($row[$this->requisiteIdColumn()] ?? 0);
		if ($requisiteId <= 0)
		{
			return $this->success($this->emptyPair());
		}

		if (!$this->isStoredPairVisible($ownerType, $ownerId))
		{
			return $this->success($this->emptyPair());
		}

		return $this->success($this->createPair($requisiteId, (int)($row[$this->bankRequisiteIdColumn()] ?? 0)));
	}

	/**
	 * @param int $requisiteId zero clears both references of the group and leaves the row in place
	 * @return Result data: the key of {@see self::resultDataKey()} => TEntity as written
	 * @throws Main\ArgumentException on a negative identifier
	 * @throws Main\DB\SqlQueryException
	 */
	public function set(EntityType $ownerType, int $ownerId, int $requisiteId, int $bankRequisiteId): Result
	{
		if (!$this->isApplicableType($ownerType))
		{
			return $this->fail($this->ownerNotFoundError());
		}

		if (!$this->ownerExists($ownerType, $ownerId))
		{
			return $this->fail($this->ownerNotFoundError());
		}

		if (!$this->access->canUpdateOwnerEntity($ownerType->getId(), $ownerId))
		{
			return $this->fail($this->ownerNotFoundError());
		}

		if ($requisiteId > 0 && !$this->isRequisiteAllowed($ownerType, $ownerId, $requisiteId))
		{
			return $this->fail($this->requisiteNotAllowedError());
		}

		if ($bankRequisiteId > 0)
		{
			if ($requisiteId <= 0)
			{
				return $this->fail($this->bankRequisiteNotAllowedError());
			}

			if (!$this->bankRequisiteBelongsToRequisite($bankRequisiteId, $requisiteId))
			{
				return $this->fail($this->bankRequisiteNotAllowedError());
			}
		}

		$this->writePair($ownerType, $ownerId, $requisiteId, $bankRequisiteId);

		// the repository writes the row past the service, and the read cache of the service caches the
		// absence of a row as well: a write left untold would stay invisible for the rest of the request
		$this->linkService->clearReadCache();

		// the written pair is answered as it stands: the right on the owner and the reach of the party
		// the pair belongs to are settled above, and reading them a second time would only disagree
		return $this->success(
			$requisiteId > 0 ? $this->createPair($requisiteId, $bankRequisiteId) : $this->emptyPair()
		);
	}

	private function bankRequisiteBelongsToRequisite(int $bankRequisiteId, int $requisiteId): bool
	{
		$row = BankDetailTable::query()
			->setSelect(['ENTITY_TYPE_ID', 'ENTITY_ID'])
			->where('ID', $bankRequisiteId)
			->setLimit(1)
			->fetch()
		;

		return is_array($row)
			&& (int)$row['ENTITY_TYPE_ID'] === OwnerType::REQUISITE
			&& (int)$row['ENTITY_ID'] === $requisiteId
		;
	}

	private function isApplicableType(EntityType $ownerType): bool
	{
		return isset($this->linkService->getAvailableEntityTypeIds()[$ownerType->getId()]);
	}

	/**
	 * Existence of the owner as the Item layer sees it. {@see AccessMode::Restricted} is what keeps
	 * it apart from the permission check that follows: a missing item comes back as `null`, an
	 * unreadable one as a data-less stub.
	 */
	private function ownerExists(EntityType $ownerType, int $ownerId): bool
	{
		return $ownerId > 0
			&& ItemProvider::forEntityType($ownerType)
				->withAccessCheck($this->userId, AccessMode::Restricted)
				->getById($ownerId, ['id']) !== null
		;
	}

	/**
	 * The stored pair of the entity. An absent row reads as `null`, on the first read and on every
	 * one after it, and means an empty pair here.
	 *
	 * @return array<string, mixed>
	 */
	private function readPair(EntityType $ownerType, int $ownerId): array
	{
		return $this->linkService->getByEntity($ownerType->getId(), $ownerId) ?? [];
	}

	/** @return TEntity */
	private function createPair(int $requisiteId, int $bankRequisiteId): object
	{
		return $this->newPair($requisiteId, $bankRequisiteId > 0 ? $bankRequisiteId : null);
	}

	/** @return TEntity */
	private function emptyPair(): object
	{
		return $this->newPair(null, null);
	}

	/** @param TEntity $pair */
	private function success(object $pair): Result
	{
		return (new Result())->setData([$this->resultDataKey() => $pair]);
	}

	private function fail(Error $error): Result
	{
		return (new Result())->addError($error);
	}
}
