<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Entity\Audit\AuditMessage;
use Bitrix\Disk\Internal\Entity\Audit\AuditSubject;
use Bitrix\Disk\Internal\Entity\Audit\AuditTarget;
use InvalidArgumentException;
use JsonException;

final class AuditIdempotencyKeyBuilder
{
	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

	/**
	 * @throws JsonException
	 */
	public function build(
		string $source,
		string $operationId,
		string $eventCode,
		string $targetType,
		string $targetId,
		?string $subjectType = null,
		?string $subjectId = null,
	): string
	{
		$parts = [
			$source,
			$operationId,
			$eventCode,
			$targetType,
			$targetId,
			$this->normalizeNullable($subjectType),
			$this->normalizeNullable($subjectId),
		];

		return hash('sha256', json_encode($parts, self::JSON_FLAGS));
	}

	/**
	 * @throws JsonException
	 */
	public function buildFromMessage(AuditMessage $message): string
	{
		return $this->build(
			source: $message->source,
			operationId: $message->operationId,
			eventCode: $message->eventCode,
			targetType: $message->target->type,
			targetId: $message->target->id,
			subjectType: $message->subject->type,
			subjectId: $message->subject->id,
		);
	}

	/**
	 * @throws JsonException
	 */
	public function buildFromArray(array $fields): string
	{
		$target = $this->extractTarget($fields);
		$subject = $this->extractSubject($fields);

		return $this->build(
			source: $this->requireString($fields, 'source'),
			operationId: $this->requireString($fields, 'operationId'),
			eventCode: $this->requireString($fields, 'eventCode'),
			targetType: $target['type'],
			targetId: $target['id'],
			subjectType: $subject['type'],
			subjectId: $subject['id'],
		);
	}

	private function extractTarget(array $fields): array
	{
		if (($fields['target'] ?? null) instanceof AuditTarget)
		{
			return [
				'type' => $fields['target']->type,
				'id' => $fields['target']->id,
			];
		}

		if (isset($fields['target']) && is_array($fields['target']))
		{
			return [
				'type' => $this->requireString($fields['target'], 'type'),
				'id' => $this->requireString($fields['target'], 'id'),
			];
		}

		return [
			'type' => $this->requireString($fields, 'targetType'),
			'id' => $this->requireString($fields, 'targetId'),
		];
	}

	private function extractSubject(array $fields): array
	{
		if (($fields['subject'] ?? null) instanceof AuditSubject)
		{
			return [
				'type' => $fields['subject']->type,
				'id' => $fields['subject']->id,
			];
		}

		if (isset($fields['subject']) && is_array($fields['subject']))
		{
			return [
				'type' => $this->optionalString($fields['subject'], 'type'),
				'id' => $this->optionalString($fields['subject'], 'id'),
			];
		}

		return [
			'type' => $this->optionalString($fields, 'subjectType'),
			'id' => $this->optionalString($fields, 'subjectId'),
		];
	}

	private function normalizeNullable(?string $value): ?string
	{
		return $value === '' ? null : $value;
	}

	private function requireString(array $data, string $key): string
	{
		if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '')
		{
			throw new InvalidArgumentException("Audit idempotency field {$key} is required.");
		}

		return $data[$key];
	}

	private function optionalString(array $data, string $key): ?string
	{
		if (!array_key_exists($key, $data) || $data[$key] === null)
		{
			return null;
		}

		if (!is_string($data[$key]))
		{
			throw new InvalidArgumentException("Audit idempotency field {$key} must be a string.");
		}

		return $this->normalizeNullable($data[$key]);
	}

	private function requireInt(array $data, string $key): int
	{
		if (!array_key_exists($key, $data) || !is_int($data[$key]))
		{
			throw new InvalidArgumentException("Audit idempotency field {$key} is required.");
		}

		return $data[$key];
	}
}
