<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Disk\EventHandler\OnAfterDeleteAttachedObject\Action;

use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;

class DetachFileFromTemplate
{
	use DetachFileFromUserFieldTrait;

	public function __invoke(int $templateId, int $attachId): void
	{
		$this->detachFileFromUserField(UserField::TEMPLATE, $templateId, $attachId);
	}
}
