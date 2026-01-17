<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;

interface CheckListEntityRepositoryInterface
{
	public function getIdByCheckListId(int $checkListId, Type $type): int;
}
