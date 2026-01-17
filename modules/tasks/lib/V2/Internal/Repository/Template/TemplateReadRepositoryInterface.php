<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\V2\Internal\Entity\Template;

interface TemplateReadRepositoryInterface
{
	public function getById(int $id, ?Select $select = null): ?Template;

	public function getAttachmentIds(int $templateId): array;
}
