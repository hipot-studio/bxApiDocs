<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum\Audit;

enum AuditEventCode: string
{
	case ObjectCreated = 'disk.audit.object.created';
	case ObjectContentChanged = 'disk.audit.object.content.changed';
	case ObjectCopied = 'disk.audit.object.copied';
	case ObjectRenamed = 'disk.audit.object.renamed';
	case ObjectMoved = 'disk.audit.object.moved';
	case ObjectMarkedDeleted = 'disk.audit.object.marked_deleted';
	case ObjectRestored = 'disk.audit.object.restored';
	case ObjectPermanentlyDeleted = 'disk.audit.object.permanently_deleted';
	case TrashcanEmptied = 'disk.audit.trashcan.emptied';

	case RightsSubjectAdded = 'disk.audit.rights.subject.added';
	case RightsSubjectChanged = 'disk.audit.rights.subject.changed';
	case RightsSubjectRemoved = 'disk.audit.rights.subject.removed';
	case RightsInheritanceEnabled = 'disk.audit.rights.inheritance.enabled';
	case RightsInheritanceDisabled = 'disk.audit.rights.inheritance.disabled';
	case RightsCascadeChanged = 'disk.audit.rights.cascade.changed';

	case PublicLinkCreated = 'disk.audit.public_link.created';
	case PublicLinkTtlChanged = 'disk.audit.public_link.ttl.changed';
	case PublicLinkPasswordEnabled = 'disk.audit.public_link.password.enabled';
	case PublicLinkPasswordDisabled = 'disk.audit.public_link.password.disabled';
	case PublicLinkAccessChanged = 'disk.audit.public_link.access.changed';
	case PublicLinkDisabled = 'disk.audit.public_link.disabled';
	case UnifiedLinkAccessChanged = 'disk.audit.unified_link.access.changed';

	case AuditLogOpened = 'disk.audit.log.opened';

	/**
	 * @return string[]
	 */
	public static function values(): array
	{
		return array_map(
			static fn(self $code): string => $code->value,
			self::cases(),
		);
	}
}
