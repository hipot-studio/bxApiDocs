<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\Template;

interface ParentTemplateRepositoryInterface
{
	public function getParent(int $templateId): Task|Template|null;
	public function getParentTemplateIds(array $templateIds): array;
}
