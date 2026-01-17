<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\Tasks\V2\Internal\Exception\CheckList\CheckListNotFoundException;
use Bitrix\Tasks\V2\Internal\Service\CheckList\CheckListFacadeResolver;

class CheckListEntityRepository implements CheckListEntityRepositoryInterface
{
	public function __construct(private readonly CheckListFacadeResolver $facadeResolver)
	{
	}

	/**
	 * @throws CheckListNotFoundException
	 */
	public function getIdByCheckListId(int $checkListId, Type $type): int
	{
		$facade = $this->facadeResolver->resolveByType($type);

		$items = $facade::getList([], ['ID' => $checkListId]);

		$item = $items[$checkListId] ?? null;
		if ($item === null)
		{
			throw new CheckListNotFoundException();
		}

		return (int)($item[$facade::$entityIdName] ?? 0);
	}
}
