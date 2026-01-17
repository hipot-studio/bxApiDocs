<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

interface SubTemplateRepositoryInterface
{
	public function containsSubTemplates(int $parentId): bool;
}
