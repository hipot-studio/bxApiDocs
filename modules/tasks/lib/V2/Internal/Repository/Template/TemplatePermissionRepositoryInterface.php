<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\V2\Internal\Entity\Template\PermissionCollection;

interface TemplatePermissionRepositoryInterface
{
	public function getPermissions(int $templateId): PermissionCollection;
}
