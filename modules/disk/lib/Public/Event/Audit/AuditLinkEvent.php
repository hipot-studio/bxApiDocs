<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Event\Audit;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Dto\Audit\AuditActionContext;
use Bitrix\Disk\Internal\Dto\Audit\AuditSubject;
use Bitrix\Disk\Internal\Enum\Audit\AuditEventCode;
use Bitrix\Disk\Internal\Service\Audit\AuditEventCodeRegistry;
use Bitrix\Main\Type\DateTime;
use InvalidArgumentException;

final class AuditLinkEvent extends AbstractAuditEvent
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
	)
	{
		if (!in_array(
			$targetType,
			[
				AuditEventCodeRegistry::TARGET_PUBLIC_LINK,
				AuditEventCodeRegistry::TARGET_UNIFIED_LINK,
			],
			true,
		))
		{
			throw new InvalidArgumentException('Link audit event target must be public_link or unified_link.');
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
		);
	}
}
