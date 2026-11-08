<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Requisite;

use Bitrix\Crm\BankDetailTable;
use Bitrix\Crm\RequisiteTable;
use Bitrix\Crm\V2\Internal\Access\Requisite\EntityLinkAccess;
use Bitrix\Crm\V2\Internal\Repository\Requisite\EntityLinkRepository;
use Bitrix\Crm\V2\Public\Entity\Requisite\ClientRequisite;
use Bitrix\Crm\V2\Public\Entity\Requisite\ClientRequisiteError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Crm\V2\Public\Provider\Item\AccessMode;
use Bitrix\Crm\V2\Public\Provider\Item\ItemProvider;
use Bitrix\Main;
use Bitrix\Main\Result;

/**
 * Reading and setting the requisite of the client chosen in a CRM entity.
 *
 * The subject of every permission check is the user given to the constructor and nobody else: the
 * scenario never asks who the current user is.
 *
 * Both halves keep the same shape. The entity that owns the choice is checked first - the type has
 * to be one the link serves, the entity has to exist, and the user has to be allowed to read it for
 * a read and to change it for a write. All three answer {@see ClientRequisiteError::OWNER_NOT_FOUND},
 * so a user who may not see an entity cannot tell it apart from one that is not there. The pair
 * itself is checked next, against the client the entity names: a requisite has to be one of that
 * client's, and a bank requisite one of that requisite's.
 *
 * A read never refuses over the pair. An entity with no row at all, an entity whose row names no
 * requisite, and an entity whose client the user may not read all answer with an empty pair - a
 * refusal in the last case would say that a client is there.
 *
 * Both identifiers arrive normalised as non-negative integers: the request layer turns a missing
 * value and `null` into zero, and the scenario does not tell the two apart. A negative identifier
 * breaks that contract instead of asking for a refusal, and {@see EntityLinkRepository::setClientPair()}
 * throws on it.
 *
 * {@see EntityLinkConsistencyChecker} belongs to the legacy write path and is not called here: it
 * throws refusals with numeric codes of its own, while the checks above are the whole rule of this
 * scenario and every refusal of it is a {@see ClientRequisiteError}.
 *
 * @internal
 */
final class ClientRequisiteService
{
	/** Result data key of both {@see self::get()} and {@see self::set()}. */
	public const DATA_CLIENT_REQUISITE = 'clientRequisite';

	private readonly EntityLinkService $linkService;

	private readonly EntityLinkRepository $linkRepository;

	private readonly EntityClientSellerResolver $clientSellerResolver;

	private readonly EntityLinkAccess $access;

	public function __construct(
		private readonly int $userId,
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

	/** @return Result data: {@see self::DATA_CLIENT_REQUISITE} => {@see ClientRequisite} */
	public function get(EntityType $ownerType, int $ownerId): Result
	{
		if (!$this->isApplicableType($ownerType))
		{
			return self::fail(ClientRequisiteError::OWNER_NOT_FOUND);
		}

		if (!$this->ownerExists($ownerType, $ownerId))
		{
			return self::fail(ClientRequisiteError::OWNER_NOT_FOUND);
		}

		if (!$this->access->canReadOwnerEntity($ownerType->getId(), $ownerId))
		{
			return self::fail(ClientRequisiteError::OWNER_NOT_FOUND);
		}

		$row = $this->readClientPair($ownerType, $ownerId);
		$requisiteId = (int)($row['REQUISITE_ID'] ?? 0);
		if ($requisiteId <= 0)
		{
			return self::success(new ClientRequisite());
		}

		$client = $this->resolveClient($ownerType, $ownerId);

		if (!self::ownerHasClient($client))
		{
			return self::success(new ClientRequisite());
		}

		if (!$this->canReadClient($client))
		{
			return self::success(new ClientRequisite());
		}

		return self::success(self::createPair($requisiteId, (int)($row['BANK_DETAIL_ID'] ?? 0)));
	}

	/**
	 * @param int $requisiteId zero clears both client references and leaves the row in place
	 * @return Result data: {@see self::DATA_CLIENT_REQUISITE} => {@see ClientRequisite} as written
	 * @throws Main\ArgumentException on a negative identifier
	 * @throws Main\DB\SqlQueryException
	 */
	public function set(EntityType $ownerType, int $ownerId, int $requisiteId, int $bankRequisiteId): Result
	{
		if (!$this->isApplicableType($ownerType))
		{
			return self::fail(ClientRequisiteError::OWNER_NOT_FOUND);
		}

		if (!$this->ownerExists($ownerType, $ownerId))
		{
			return self::fail(ClientRequisiteError::OWNER_NOT_FOUND);
		}

		if (!$this->access->canUpdateOwnerEntity($ownerType->getId(), $ownerId))
		{
			return self::fail(ClientRequisiteError::OWNER_NOT_FOUND);
		}

		$client = $this->resolveClient($ownerType, $ownerId);

		if ($requisiteId > 0)
		{
			if (!self::ownerHasClient($client))
			{
				return self::fail(ClientRequisiteError::REQUISITE_NOT_ALLOWED);
			}

			if (!$this->canReadClient($client))
			{
				return self::fail(ClientRequisiteError::REQUISITE_NOT_ALLOWED);
			}

			if (!$this->requisiteBelongsToClient($requisiteId, $client))
			{
				return self::fail(ClientRequisiteError::REQUISITE_NOT_ALLOWED);
			}
		}

		if ($bankRequisiteId > 0)
		{
			if ($requisiteId <= 0)
			{
				return self::fail(ClientRequisiteError::BANK_REQUISITE_NOT_ALLOWED);
			}

			if (!$this->bankRequisiteBelongsToRequisite($bankRequisiteId, $requisiteId))
			{
				return self::fail(ClientRequisiteError::BANK_REQUISITE_NOT_ALLOWED);
			}
		}

		$this->linkRepository->setClientPair($ownerType->getId(), $ownerId, $requisiteId, $bankRequisiteId);

		// the repository writes the row past the service, and the read cache of the service caches the
		// absence of a row as well: a write left untold would stay invisible for the rest of the request
		$this->linkService->clearReadCache();

		// the written pair is answered as it stands: the right on the owner and the reach of the
		// client are settled above, and reading them a second time would only disagree with that
		return self::success($requisiteId > 0 ? self::createPair($requisiteId, $bankRequisiteId) : new ClientRequisite());
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
	private function readClientPair(EntityType $ownerType, int $ownerId): array
	{
		return $this->linkService->getByEntity($ownerType->getId(), $ownerId) ?? [];
	}

	/**
	 * @return array{typeId: int, id: int} zeros when the entity names no client, and when the entity
	 *                                     went away between the existence check and this read
	 */
	private function resolveClient(EntityType $ownerType, int $ownerId): array
	{
		try
		{
			$info = $this->clientSellerResolver->getClientSellerInfo($ownerType->getId(), $ownerId);
		}
		catch (Main\SystemException)
		{
			return ['typeId' => OwnerType::UNDEFINED, 'id' => 0];
		}

		return [
			'typeId' => (int)$info['CLIENT_ENTITY_TYPE_ID'],
			'id' => (int)$info['CLIENT_ENTITY_ID'],
		];
	}

	/** @param array{typeId: int, id: int} $client */
	private static function ownerHasClient(array $client): bool
	{
		return $client['typeId'] !== OwnerType::UNDEFINED && $client['id'] > 0;
	}

	/**
	 * An unnamed entity widens {@see EntityLinkAccess::canReadOwnerEntity()} into "is there any type
	 * the user may read", so an empty client is refused here rather than reaching it: the invariant
	 * then holds wherever the question is asked from, not only after {@see self::ownerHasClient()}.
	 *
	 * @param array{typeId: int, id: int} $client
	 */
	private function canReadClient(array $client): bool
	{
		return self::ownerHasClient($client)
			&& $this->access->canReadOwnerEntity($client['typeId'], $client['id'])
		;
	}

	/** @param array{typeId: int, id: int} $client */
	private function requisiteBelongsToClient(int $requisiteId, array $client): bool
	{
		$row = RequisiteTable::query()
			->setSelect(['ENTITY_TYPE_ID', 'ENTITY_ID'])
			->where('ID', $requisiteId)
			->setLimit(1)
			->fetch()
		;

		return is_array($row)
			&& (int)$row['ENTITY_TYPE_ID'] === $client['typeId']
			&& (int)$row['ENTITY_ID'] === $client['id']
		;
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

	private static function createPair(int $requisiteId, int $bankRequisiteId): ClientRequisite
	{
		return new ClientRequisite($requisiteId, $bankRequisiteId > 0 ? $bankRequisiteId : null);
	}

	private static function success(ClientRequisite $pair): Result
	{
		return (new Result())->setData([self::DATA_CLIENT_REQUISITE => $pair]);
	}

	private static function fail(ClientRequisiteError $error): Result
	{
		return (new Result())->addError($error->toError());
	}
}
