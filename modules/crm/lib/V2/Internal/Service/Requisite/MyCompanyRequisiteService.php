<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Requisite;

use Bitrix\Crm\RequisiteTable;
use Bitrix\Crm\V2\Internal\Access\Requisite\EntityLinkAccess;
use Bitrix\Crm\V2\Internal\Repository\Requisite\EntityLinkRepository;
use Bitrix\Crm\V2\Internal\Repository\Requisite\MyCompanyRequisiteRepository;
use Bitrix\Crm\V2\Public\Entity\Requisite\MyCompanyRequisite;
use Bitrix\Crm\V2\Public\Entity\Requisite\MyCompanyRequisiteError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main;
use Bitrix\Main\Error;

/**
 * Reading and setting the requisite of the seller chosen in a CRM entity - the requisite of the
 * company the entity names as "my company" - on the scenario of
 * {@see AbstractOwnerRequisitePairService}.
 *
 * The class is about the choice made in an entity and not about the requisites of a company:
 * {@see MyCompanyRequisiteRepository}, whose name is next to this one, reads and writes the requisite
 * records of the seller companies themselves and knows nothing of any entity choosing one.
 *
 * The party the pair belongs to is the company the entity names as its own: that company has to be
 * marked as a seller company of the portal, and a requisite has to be one of that company's.
 *
 * The right on the entity is the whole rule of the write, and of the read as well: the card of the
 * seller company has rights of its own, held elsewhere and answering a different question, and the
 * choice made in the entity follows the entity. Its identifier is never passed to
 * {@see EntityLinkAccess::canReadOwnerEntity()}, and a stored pair is answered as it was written -
 * the group hides nothing on a read.
 *
 * {@see EntityLinkConsistencyChecker::isMyCompany()}, the mark of a seller company, is the only thing
 * taken from the checker of the legacy write path.
 *
 * @extends AbstractOwnerRequisitePairService<MyCompanyRequisite>
 *
 * @internal
 */
final class MyCompanyRequisiteService extends AbstractOwnerRequisitePairService
{
	/** Result data key of both {@see self::get()} and {@see self::set()}. */
	public const DATA_MY_COMPANY_REQUISITE = 'myCompanyRequisite';

	private readonly EntityLinkConsistencyChecker $consistencyChecker;

	public function __construct(
		int $userId,
		?EntityLinkService $linkService = null,
		?EntityLinkRepository $linkRepository = null,
		?EntityClientSellerResolver $clientSellerResolver = null,
		?EntityLinkConsistencyChecker $consistencyChecker = null,
		?EntityLinkAccess $access = null,
	)
	{
		parent::__construct($userId, $linkService, $linkRepository, $clientSellerResolver, $access);

		$this->consistencyChecker = $consistencyChecker ?? new EntityLinkConsistencyChecker();
	}

	protected function resultDataKey(): string
	{
		return self::DATA_MY_COMPANY_REQUISITE;
	}

	protected function requisiteIdColumn(): string
	{
		return 'MC_REQUISITE_ID';
	}

	protected function bankRequisiteIdColumn(): string
	{
		return 'MC_BANK_DETAIL_ID';
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
		$this->linkRepository->setMyCompanyPair($ownerType->getId(), $ownerId, $requisiteId, $bankRequisiteId);
	}

	protected function newPair(?int $requisiteId, ?int $bankRequisiteId): MyCompanyRequisite
	{
		return new MyCompanyRequisite($requisiteId, $bankRequisiteId);
	}

	protected function ownerNotFoundError(): Error
	{
		return MyCompanyRequisiteError::OWNER_NOT_FOUND->toError();
	}

	protected function requisiteNotAllowedError(): Error
	{
		return MyCompanyRequisiteError::REQUISITE_NOT_ALLOWED->toError();
	}

	protected function bankRequisiteNotAllowedError(): Error
	{
		return MyCompanyRequisiteError::BANK_REQUISITE_NOT_ALLOWED->toError();
	}

	protected function isRequisiteAllowed(EntityType $ownerType, int $ownerId, int $requisiteId): bool
	{
		$myCompanyId = $this->resolveMyCompany($ownerType, $ownerId);

		return $myCompanyId > 0
			&& $this->consistencyChecker->isMyCompany($myCompanyId)
			&& $this->requisiteBelongsToMyCompany($requisiteId, $myCompanyId)
		;
	}

	/**
	 * @return int zero when the entity names no company of its own, and when the entity went away
	 *             between the existence check and this read
	 */
	private function resolveMyCompany(EntityType $ownerType, int $ownerId): int
	{
		try
		{
			$info = $this->clientSellerResolver->getClientSellerInfo($ownerType->getId(), $ownerId);
		}
		catch (Main\SystemException)
		{
			return 0;
		}

		return (int)$info['MYCOMPANY_ID'];
	}

	private function requisiteBelongsToMyCompany(int $requisiteId, int $myCompanyId): bool
	{
		$row = RequisiteTable::query()
			->setSelect(['ENTITY_TYPE_ID', 'ENTITY_ID'])
			->where('ID', $requisiteId)
			->setLimit(1)
			->fetch()
		;

		return is_array($row)
			&& (int)$row['ENTITY_TYPE_ID'] === OwnerType::COMPANY
			&& (int)$row['ENTITY_ID'] === $myCompanyId
		;
	}
}
