<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Event\Audit;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Dto\Audit\AuditActionContext;
use Bitrix\Disk\Internal\Dto\Audit\AuditObjectSnapshot;
use Bitrix\Disk\Internal\Dto\Audit\AuditSubject;
use Bitrix\Disk\Internal\Enum\Audit\AuditEventCode;
use Bitrix\Disk\Internal\Service\Audit\AuditEventCodeRegistry;
use Bitrix\Main\Type\DateTime;
use InvalidArgumentException;

final class AuditRightsEvent extends AbstractAuditEvent
{
	public function __construct(
		AuditEventCode $eventCode,
		string $operationId,
		string $source,
		AuditActionContext $actionContext,
		string $targetType,
		string $targetId,
		?BaseObject $targetObject = null,
		?AuditSubject $subject = null,
		array $details = [],
		?DateTime $occurredAt = null,
		?AuditObjectSnapshot $objectSnapshot = null,
	)
	{
		if ($targetType !== AuditEventCodeRegistry::TARGET_RIGHTS)
		{
			throw new InvalidArgumentException('Rights audit event target must be rights.');
		}

		parent::__construct(
			eventCode: $eventCode,
			operationId: $operationId,
			source: $source,
			actionContext: $actionContext,
			targetType: $targetType,
			targetId: $targetId,
			targetObject: $targetObject,
			subject: $subject,
			details: $details,
			occurredAt: $occurredAt,
			objectSnapshot: $objectSnapshot,
		);
	}
}
