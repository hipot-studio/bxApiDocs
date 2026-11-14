<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Requisite;

use Bitrix\Crm\V2\Internal\Service\Requisite\MyCompanyRequisiteService;
use Bitrix\Crm\V2\Public\Entity\Requisite\MyCompanyRequisite;
use Bitrix\Crm\V2\Public\Entity\Requisite\MyCompanyRequisiteError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;

/**
 * The requisite of the seller chosen in a CRM entity - the requisite of the company the entity names
 * as "my company".
 *
 * The subject of every permission check is the user given to the constructor: the reading of the pair
 * follows the read right of the entity that owns it, and nothing here asks who the current user is.
 * The card of the seller company has rights of its own and is not asked about.
 *
 * A read answers with a pair, and an entity that has chosen nothing answers with an empty one - the
 * absence of a choice is not a refusal. {@see MyCompanyRequisiteError::OWNER_NOT_FOUND} is the only
 * refusal the read has, and it covers an entity that is not there, one of a type that cannot own the
 * choice at all, and one the user may not see.
 */
final class MyCompanyRequisiteProvider
{
	/** Result data key of {@see self::get()}. */
	public const DATA_MY_COMPANY_REQUISITE = MyCompanyRequisiteService::DATA_MY_COMPANY_REQUISITE;

	public function __construct(
		private readonly int $userId,
	)
	{
	}

	/** @return Result data: {@see self::DATA_MY_COMPANY_REQUISITE} => {@see MyCompanyRequisite} */
	public function get(EntityType $ownerType, int $ownerId): Result
	{
		return (new MyCompanyRequisiteService($this->userId))->get($ownerType, $ownerId);
	}
}
