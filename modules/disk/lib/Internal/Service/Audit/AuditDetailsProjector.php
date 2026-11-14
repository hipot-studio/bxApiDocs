<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Main\Diag\LoggerFactory;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AuditDetailsProjector
{
	private const MAX_RAW_SIZE = 65536;
	private const MAX_DEPTH = 2;
	private const MAX_RIGHTS_DEPTH = 4;
	private const MAX_COLLECTION_SIZE = 50;
	private const MAX_STRING_LENGTH = 1000;

	private const TYPE_MAP = [
		'objectType' => ['string'],
		'oldSize' => ['integer'],
		'newSize' => ['integer'],
		'sourceObjectId' => ['integer'],
		'targetParentId' => ['integer'],
		'oldName' => ['string'],
		'newName' => ['string'],
		'oldParentId' => ['integer'],
		'newParentId' => ['integer'],
		'oldStorageId' => ['integer'],
		'newStorageId' => ['integer'],
		'deletedType' => ['integer'],
		'restoredParentId' => ['integer'],
		'deleteScope' => ['string'],
		'storageId' => ['integer'],
		'accessCode' => ['string'],
		'oldTaskName' => ['string'],
		'newTaskName' => ['string'],
		'oldDomain' => ['string'],
		'newDomain' => ['string'],
		'oldNegative' => ['boolean'],
		'newNegative' => ['boolean'],
		'sourceGroupId' => ['integer'],
		'targetGroupId' => ['integer'],
		'oldRights' => ['array'],
		'newRights' => ['array'],
		'oldMode' => ['string'],
		'newMode' => ['string'],
		'cascadeRootObjectId' => ['integer'],
		'addedCount' => ['integer'],
		'changedCount' => ['integer'],
		'removedCount' => ['integer'],
		'accessRight' => ['integer'],
		'ttlSet' => ['boolean'],
		'passwordEnabled' => ['boolean'],
		'canEditSettings' => ['boolean'],
		'oldTtl' => ['integer', 'NULL'],
		'newTtl' => ['integer', 'NULL'],
		'oldAccessRight' => ['integer'],
		'newAccessRight' => ['integer'],
		'canDownloadWithReadAccess' => ['boolean'],
		'disabled' => ['boolean'],
		'oldAccessLevel' => ['string'],
		'newAccessLevel' => ['string'],
		'oldEnabled' => ['string'],
		'newEnabled' => ['string'],
		'oldRetentionPeriod' => ['string'],
		'newRetentionPeriod' => ['string'],
	];

	private readonly LoggerInterface $logger;
	private readonly AuditSecretDenylist $secretDenylist;

	public function __construct(
		?LoggerInterface $logger = null,
		?AuditSecretDenylist $secretDenylist = null,
	)
	{
		$this->logger = $logger ?? (new LoggerFactory())->createById('disk.audit.query') ?? new NullLogger();
		$this->secretDenylist = $secretDenylist ?? new AuditSecretDenylist();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function project(int $eventId, string $eventCode, ?string $rawDetails): array
	{
		if ($rawDetails === null || $rawDetails === '')
		{
			return [];
		}

		if (strlen($rawDetails) > self::MAX_RAW_SIZE)
		{
			$this->logCorruption($eventId, 'size_limit');

			return [];
		}

		try
		{
			$depth = AuditRightsDetails::isSubjectEvent($eventCode) ? self::MAX_RIGHTS_DEPTH : self::MAX_DEPTH;
			$details = json_decode($rawDetails, true, $depth + 1, JSON_THROW_ON_ERROR);
		}
		catch (JsonException $exception)
		{
			$errorType = $exception->getCode() === JSON_ERROR_DEPTH ? 'depth_limit' : 'invalid_json';
			$this->logCorruption($eventId, $errorType);

			return [];
		}

		if (!is_array($details) || ($details !== [] && array_is_list($details)))
		{
			$this->logCorruption($eventId, 'invalid_root');

			return [];
		}

		$scalarDetails = $details;
		if (AuditRightsDetails::isSubjectEvent($eventCode))
		{
			try
			{
				AuditRightsDetails::validate($eventCode, $details);
			}
			catch (InvalidArgumentException)
			{
				$this->logCorruption($eventId, 'invalid_rights');

				return [];
			}
			unset($scalarDetails['oldRights'], $scalarDetails['newRights']);
		}

		if (count($details) > self::MAX_COLLECTION_SIZE || !$this->isStructureSafe($scalarDetails, 1))
		{
			$this->logCorruption($eventId, 'structure_limit');

			return [];
		}

		$projected = [];
		foreach ($this->getAllowedKeys($eventCode) as $key)
		{
			if (
				!array_key_exists($key, $details)
				|| $this->secretDenylist->isDenied($key)
				|| !$this->hasAllowedType($key, $details[$key])
			)
			{
				continue;
			}

			$projected[$key] = $details[$key];
		}

		return $projected;
	}

	private function getAllowedKeys(string $eventCode): array
	{
		if (AuditEventCodeRegistry::has($eventCode))
		{
			return AuditEventCodeRegistry::getAllowedDetailKeys($eventCode);
		}

		if (in_array($eventCode, AuditSettingsEventFactory::eventCodes(), true))
		{
			return $eventCode === AuditSettingsEventFactory::EVENT_RETENTION_CHANGED
				? AuditSettingsEventFactory::retentionDetailKeys()
				: AuditSettingsEventFactory::allowedDetailKeys();
		}

		return [];
	}

	private function hasAllowedType(string $key, mixed $value): bool
	{
		$allowedTypes = self::TYPE_MAP[$key] ?? [];

		return in_array(gettype($value), $allowedTypes, true);
	}

	private function isStructureSafe(mixed $value, int $depth): bool
	{
		if (is_string($value))
		{
			return mb_strlen($value) <= self::MAX_STRING_LENGTH;
		}

		if ($value === null || is_scalar($value))
		{
			return true;
		}

		if (!is_array($value) || $depth > self::MAX_DEPTH || count($value) > self::MAX_COLLECTION_SIZE)
		{
			return false;
		}

		foreach ($value as $nestedValue)
		{
			if (!$this->isStructureSafe($nestedValue, $depth + 1))
			{
				return false;
			}
		}

		return true;
	}

	private function logCorruption(int $eventId, string $errorType): void
	{
		$this->logger->warning('Disk audit details could not be projected.', [
			'eventId' => $eventId,
			'errorType' => $errorType,
		]);
	}
}
