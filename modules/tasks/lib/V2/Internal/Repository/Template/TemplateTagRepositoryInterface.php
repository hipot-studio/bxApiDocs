<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\V2\Internal\Entity\Template\TagCollection;

interface TemplateTagRepositoryInterface
{
	public function getById(int $templateId): TagCollection;

	public function invalidate(int $taskId): void;
}
