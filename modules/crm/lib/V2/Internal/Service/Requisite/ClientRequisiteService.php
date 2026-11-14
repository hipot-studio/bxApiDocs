<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Requisite;

use Bitrix\Crm\RequisiteTable;
use Bitrix\Crm\V2\Internal\Access\Requisite\EntityLinkAccess;
use Bitrix\Crm\V2\Public\Entity\Requisite\ClientRequisite;
use Bitrix\Crm\V2\Public\Entity\Requisite\ClientRequisiteError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main;
use Bitrix\Main\Error;

/**
 * Reading and setting the requisite of the client chosen in a CRM entity, on the scenario of
 * {@see AbstractOwnerRequisitePairService}.
 *
 * The party the pair belongs to is the client the entity names: a requisite has to be one of that
 * client's, and the user has to be allowed to read the client itself.
 *
 * A read answers an empty pair rather than a refusal when the client is out of reach, a refusal there
 * would say that a client is named at all.
 *
 * @extends AbstractOwnerRequisitePairService<ClientRequisite>
 *
 * @internal
 */
final class ClientRequisiteService extends AbstractOwnerRequisitePairService
{
	/** Result data key of both {@see self::get()} and {@see self::set()}. */
	public const DATA_CLIENT_REQUISITE = 'clientRequisite';

	protected function resultDataKey(): string
	{
		return self::DATA_CLIENT_REQUISITE;
	}

	protected function requisiteIdColumn(): string
	{
		return 'REQUISITE_ID';
	}

	protected function bankRequisiteIdColumn(): string
	{
		return 'BANK_DETAIL_ID';
	}

	/**
	 * @throws Main\ArgumentException on a negative identifier
	 * @throws Main\DB\SqlQueryException
	 */
	protected function writePair(
		EntityType $ownerType,
		int $ownerId,
		int $requisiteId,
		int $bankRequisiteId,
	): void
	{
		$this->linkRepository->setClientPair($ownerType->getId(), $ownerId, $requisiteId, $bankRequisiteId);
	}

	protected function newPair(?int $requisiteId, ?int $bankRequisiteId): ClientRequisite
	{
		return new ClientRequisite($requisiteId, $bankRequisiteId);
	}

	protected function ownerNotFoundError(): Error
	{
		return ClientRequisiteError::OWNER_NOT_FOUND->toError();
	}

	protected function requisiteNotAllowedError(): Error
	{
		return ClientRequisiteError::REQUISITE_NOT_ALLOWED->toError();
	}

	protected function bankRequisiteNotAllowedError(): Error
	{
		return ClientRequisiteError::BANK_REQUISITE_NOT_ALLOWED->toError();
	}

	protected function isRequisiteAllowed(EntityType $ownerType, int $ownerId, int $requisiteId): bool
	{
		$client = $this->resolveClient($ownerType, $ownerId);

		return $this->canReadClient($client)
			&& $this->requisiteBelongsToClient($requisiteId, $client)
		;
	}

	protected function isStoredPairVisible(EntityType $ownerType, int $ownerId): bool
	{
		return $this->canReadClient($this->resolveClient($ownerType, $ownerId));
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
}
