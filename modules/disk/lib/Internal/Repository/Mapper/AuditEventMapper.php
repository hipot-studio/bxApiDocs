<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Mapper;

use Bitrix\Disk\Internal\Entity\Audit\AuditMessage;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

final class AuditEventMapper
{
	public function convertToFields(AuditMessage $message): array
	{
		return [
			'IDEMPOTENCY_KEY' => $message->idempotencyKey,
			'EVENT_CODE' => $message->eventCode,
			'RESULT' => $message->result,
			'OCCURRED_AT' => clone $message->occurredAt,
			'CREATED_AT' => new DateTime(),
			'STORAGE_ID' => $message->storageId,
			'STORAGE_NAME' => $message->storageName,
			'INITIATOR_TYPE' => $message->initiator->type,
			'INITIATOR_ID' => $message->initiator->id,
			'INITIATOR_NAME' => $message->initiator->name,
			'IP' => $message->initiator->ip,
			'CHANNEL' => $message->channel,
			'CONTEXT' => $message->context->type,
			'CONTEXT_ENTITY_TYPE' => $message->context->entityType,
			'CONTEXT_ENTITY_ID' => $message->context->entityId,
			'CONTEXT_ENTITY_NAME' => $message->context->entityName,
			'TARGET_TYPE' => $message->target->type,
			'TARGET_ID' => $message->target->id,
			'TARGET_NAME' => $message->target->name,
			'TARGET_NAME_SEARCH' => $message->target->nameSearch,
			'TARGET_PATH' => $message->target->path,
			'TARGET_PARENT_ID' => $message->target->parentId,
			'SUBJECT_TYPE' => $message->subject->type,
			'SUBJECT_ID' => $message->subject->id,
			'SUBJECT_NAME' => $message->subject->name,
			'DETAILS' => $this->encodeDetails($message->details),
		];
	}

	private function encodeDetails(array $details): ?string
	{
		if ($details === [])
		{
			return null;
		}

		return Json::encode($details);
	}
}
