<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity\Audit;

use Bitrix\Disk\Internal\Service\Audit\AuditRightsDetails;
use Bitrix\Main\Messenger\Entity\AbstractMessage;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Type\DateTime;
use InvalidArgumentException;

final class AuditMessage extends AbstractMessage
{
	private const IDEMPOTENCY_KEY_PATTERN = '/^[a-f0-9]{64}$/';

	public readonly string $idempotencyKey;
	public readonly string $source;
	public readonly string $operationId;
	public readonly string $eventCode;
	public readonly string $result;
	public readonly DateTime $occurredAt;
	public readonly ?int $storageId;
	public readonly ?string $storageName;
	public readonly AuditInitiator $initiator;
	public readonly string $channel;
	public readonly AuditContext $context;
	public readonly AuditTarget $target;
	public readonly AuditSubject $subject;
	public readonly array $details;

	public function __construct(
		string $idempotencyKey,
		AuditEvent $event,
		AuditInitiator $initiator,
		string $channel,
		AuditContext $context,
		AuditTarget $target,
		AuditSubject $subject,
		array $details = [],
	)
	{
		$this->assertIdempotencyKey($idempotencyKey);
		AuditChannel::assertKnown($channel);

		$this->idempotencyKey = $idempotencyKey;
		$this->source = $event->source;
		$this->operationId = $event->operationId;
		$this->eventCode = $event->eventCode;
		$this->result = $event->result;
		$this->occurredAt = clone $event->occurredAt;
		$this->storageId = $event->storageId;
		$this->storageName = $event->storageName;
		$this->initiator = $initiator;
		$this->channel = $channel;
		$this->context = $context;
		$this->target = $target;
		$this->subject = $subject;
		AuditRightsDetails::validate($event->eventCode, $details);
		$this->details = $details;
	}

	public static function createFromData(array $data): MessageInterface
	{
		return new self(
			idempotencyKey: self::requireString($data, 'idempotencyKey'),
			event: AuditEvent::createFromData($data),
			initiator: AuditInitiator::createFromData(self::requireArray($data, 'initiator')),
			channel: self::requireString($data, 'channel'),
			context: AuditContext::createFromData(self::requireArray($data, 'context')),
			target: AuditTarget::createFromData(self::requireArray($data, 'target')),
			subject: AuditSubject::createFromData($data['subject'] ?? null),
			details: self::optionalArray($data, 'details'),
		);
	}

	public function jsonSerialize(): array
	{
		return [
			'idempotencyKey' => $this->idempotencyKey,
			'source' => $this->source,
			'operationId' => $this->operationId,
			'eventCode' => $this->eventCode,
			'result' => $this->result,
			'occurredAt' => $this->occurredAt->format(\DateTimeInterface::ATOM),
			'storageId' => $this->storageId,
			'storageName' => $this->storageName,
			'initiator' => $this->initiator->jsonSerialize(),
			'channel' => $this->channel,
			'context' => $this->context->jsonSerialize(),
			'target' => $this->target->jsonSerialize(),
			'subject' => $this->subject->jsonSerialize(),
			'details' => $this->details,
		];
	}

	private static function assertIdempotencyKey(string $idempotencyKey): void
	{
		if (!preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey))
		{
			throw new InvalidArgumentException('Audit idempotencyKey must be a lower-case sha256 hex string.');
		}
	}

	private static function requireString(array $data, string $key): string
	{
		if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '')
		{
			throw new InvalidArgumentException("Audit message field {$key} is required.");
		}

		return $data[$key];
	}

	private static function requireArray(array $data, string $key): array
	{
		if (!isset($data[$key]) || !is_array($data[$key]))
		{
			throw new InvalidArgumentException("Audit message field {$key} is required.");
		}

		return $data[$key];
	}

	private static function optionalArray(array $data, string $key): array
	{
		if (!array_key_exists($key, $data) || $data[$key] === null)
		{
			return [];
		}

		if (!is_array($data[$key]))
		{
			throw new InvalidArgumentException("Audit message field {$key} must be an array.");
		}

		return $data[$key];
	}
}
