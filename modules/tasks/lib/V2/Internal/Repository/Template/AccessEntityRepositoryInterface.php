<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\V2\Internal\Entity\Template\Access\AccessEntityCollection;

interface AccessEntityRepositoryInterface
{
	public function getByAccessCodes(array $accessCodes): AccessEntityCollection;
}
