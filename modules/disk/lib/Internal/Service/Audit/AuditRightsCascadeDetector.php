<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\ObjectTable;

final class AuditRightsCascadeDetector
{
	public function isCascadeCommand(
		BaseObject $object,
		?AuditOperationContext $context = null,
	): bool
	{
		$context ??= AuditOperationContextStack::current();
		if ($this->isExplicitMassOperation($context))
		{
			return false;
		}

		return $object instanceof Folder || (int)$object->getType() === ObjectTable::TYPE_FOLDER;
	}

	public function getCascadeRootObjectId(BaseObject $object): int
	{
		return (int)($object->getRealObjectId() ?: $object->getId());
	}

	private function isExplicitMassOperation(?AuditOperationContext $context): bool
	{
		return $context !== null
			&& $context->actionKind === AuditOperationContext::ACTION_EXPLICIT_MASS_OPERATION
			&& count($context->selectedTargetIds) > 1;
	}
}
