<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;

class InMemoryCheckListEntityRepository implements CheckListEntityRepositoryInterface
{
	private CheckListEntityRepositoryInterface $checkListEntityRepository;

	private array $idsCache = [];

	public function __construct(CheckListEntityRepository $checkListEntityRepository)
	{
		$this->checkListEntityRepository = $checkListEntityRepository;
	}

	public function getIdByCheckListId(int $checkListId, Type $type): int
	{
		$key = "{$checkListId}_{$type->value}";
		if (!isset($this->idsCache[$key]))
		{
			$this->idsCache[$key] = $this->checkListEntityRepository->getIdByCheckListId($checkListId, $type);
		}

		return $this->idsCache[$key];
	}
}
