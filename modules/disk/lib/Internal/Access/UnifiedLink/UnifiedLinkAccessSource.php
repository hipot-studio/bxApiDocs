<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

enum UnifiedLinkAccessSource: string
{
	case ParentExternalLink = 'parentExternalLink';
	case ExternalLink = 'externalLink';
	case ParentUnifiedLink = 'parentUnifiedLink';
	case UnifiedLink = 'unifiedLink';
	case PermissionSystem = 'permissionSystem';
	case AttachedObject = 'attachedObject';
}
