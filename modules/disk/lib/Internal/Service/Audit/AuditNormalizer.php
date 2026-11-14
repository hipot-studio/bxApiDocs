<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Enum\Audit\AuditEventCode;

final class AuditNormalizer
{
	public function shouldEmit(
		AuditEventCode $eventCode,
		string $targetId,
		array $details = [],
		?AuditOperationContext $context = null,
	): bool
	{
		$context ??= AuditOperationContextStack::current();
		if ($context === null)
		{
			return true;
		}

		return match ($context->actionKind)
		{
			AuditOperationContext::ACTION_CROSS_STORAGE_MOVE => $this->shouldEmitCrossStorageMove($eventCode),
			AuditOperationContext::ACTION_EXPLICIT_COPY => $this->shouldEmitExplicitCopy(
				$eventCode,
				$details,
				$context,
			),
			AuditOperationContext::ACTION_EXPLICIT_MASS_OPERATION => $this->shouldEmitExplicitMassOperation(
				$targetId,
				$details,
				$context,
			),
			AuditOperationContext::ACTION_CASCADE_OPERATION => $this->shouldEmitCascadeOperation(
				$targetId,
				$details,
				$context,
			),
			default => true,
		};
	}

	public function resolveOperationId(?AuditOperationContext $context = null): string
	{
		return ($context ?? AuditOperationContextStack::current())?->operationId
			?? AuditOperationContextStack::createOperationId();
	}

	private function shouldEmitCrossStorageMove(AuditEventCode $eventCode): bool
	{
		return !in_array(
			$eventCode,
			[
				AuditEventCode::ObjectCreated,
				AuditEventCode::ObjectCopied,
				AuditEventCode::ObjectMarkedDeleted,
			],
			true,
		);
	}

	private function shouldEmitExplicitCopy(
		AuditEventCode $eventCode,
		array $details,
		AuditOperationContext $context,
	): bool
	{
		if ($eventCode === AuditEventCode::ObjectCreated)
		{
			return false;
		}

		if ($eventCode !== AuditEventCode::ObjectCopied)
		{
			return true;
		}

		$sourceObjectId = isset($details['sourceObjectId']) ? (string)$details['sourceObjectId'] : '';

		return $context->hasSelectedTarget($sourceObjectId);
	}

	private function shouldEmitExplicitMassOperation(
		string $targetId,
		array $details,
		AuditOperationContext $context,
	): bool
	{
		return $context->hasSelectedTarget($this->resolveSelectedTargetId($targetId, $details));
	}

	private function shouldEmitCascadeOperation(
		string $targetId,
		array $details,
		AuditOperationContext $context,
	): bool
	{
		$cascadeRootTargetId = $context->cascadeRootTargetId;
		if ($cascadeRootTargetId === null || $cascadeRootTargetId === '')
		{
			return true;
		}

		return $this->resolveSelectedTargetId($targetId, $details) === $cascadeRootTargetId;
	}

	private function resolveSelectedTargetId(string $targetId, array $details): string
	{
		return isset($details['sourceObjectId']) ? (string)$details['sourceObjectId'] : $targetId;
	}
}
