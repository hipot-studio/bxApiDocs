<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Requisite;

use Bitrix\Crm\V2\Internal\Service\Requisite\ClientRequisiteService;
use Bitrix\Crm\V2\Public\Entity\Requisite\ClientRequisite;
use Bitrix\Crm\V2\Public\Entity\Requisite\ClientRequisiteError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;

/**
 * The requisite of the client chosen in a CRM entity.
 *
 * The subject of every permission check is the user given to the constructor: the reading of the pair
 * follows the read right of the entity that owns it, and nothing here asks who the current user is.
 *
 * A read answers with a pair, and an entity that has chosen nothing answers with an empty one - the
 * absence of a choice is not a refusal. {@see ClientRequisiteError::OWNER_NOT_FOUND} is the only
 * refusal the read has, and it covers an entity that is not there, one of a type that cannot own the
 * choice at all, and one the user may not see.
 */
final class ClientRequisiteProvider
{
	/** Result data key of {@see self::get()}. */
	public const DATA_CLIENT_REQUISITE = 'clientRequisite';

	public function __construct(
		private readonly int $userId,
	)
	{
	}

	/** @return Result data: {@see self::DATA_CLIENT_REQUISITE} => {@see ClientRequisite} */
	public function get(EntityType $ownerType, int $ownerId): Result
	{
		return (new ClientRequisiteService($this->userId))->get($ownerType, $ownerId);
	}
}
