<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Dictionary;

use Bitrix\Crm\V2\Internal\Repository\Dictionary\CommunicationTypeRepository;
use Bitrix\Crm\V2\Public\Entity\Dictionary\CommunicationChannel;
use Bitrix\Crm\V2\Public\Entity\Dictionary\CommunicationType;

final class CommunicationTypeProvider
{
	private CommunicationTypeRepository $repository;

	public function __construct()
	{
		$this->repository = new CommunicationTypeRepository();
	}

	/**
	 * A code no channel value carries is an absent entry, not an error.
	 *
	 * The captions come in $languageId; an identifier that is not a pair of lowercase letters is read
	 * as en. A phrase the requested language does not carry comes in the default language of that
	 * identifier - the rule of the language resources themselves, en for anything they do not know and
	 * ru for kz, by and uz - so a language nobody translated is answered in en rather than in codes. A
	 * caption neither language carries is null, which is not an error either.
	 */
	public function getById(CommunicationChannel $channel, string $id, string $languageId): ?CommunicationType
	{
		return $this->repository->getById($channel, $id, $languageId);
	}

	/**
	 * The whole dictionary of the channel, with the captions read as getById reads them.
	 *
	 * @return list<CommunicationType>
	 */
	public function getList(CommunicationChannel $channel, string $languageId): array
	{
		return $this->repository->getList($channel, $languageId);
	}
}
