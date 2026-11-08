<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Sync;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\TimelineActivitySync;
use Bitrix\Crm\V2\Internal\Integration\Call\SyncChatGateway;
use Bitrix\Main\Result;

/**
 * Not final: a test seam.
 *
 * @internal
 */
class SyncAvailability
{
	public const SUPPORTED_ENTITY_TYPE_IDS = [\CCrmOwnerType::Deal, \CCrmOwnerType::Lead];

	private SyncChatGateway $chatGateway;

	public function __construct(?SyncChatGateway $chatGateway = null)
	{
		$this->chatGateway = $chatGateway ?? new SyncChatGateway();
	}

	public function isSupportedEntityType(int $entityTypeId): bool
	{
		return in_array($entityTypeId, self::SUPPORTED_ENTITY_TYPE_IDS, true);
	}

	public function isFeatureEnabled(): bool
	{
		return Feature::enabled(TimelineActivitySync::class);
	}

	public function isCallModuleAvailable(): bool
	{
		return $this->chatGateway->isModuleAvailable();
	}

	public function isSyncPresetEnabled(): bool
	{
		return $this->chatGateway->isSyncPresetEnabled();
	}

	public function check(int $entityTypeId): Result
	{
		$result = new Result();

		if (!$this->isSupportedEntityType($entityTypeId))
		{
			return $result->addError(ErrorCode::getEntityTypeNotSupportedError($entityTypeId));
		}

		if (!$this->isFeatureEnabled())
		{
			return $result->addError(ErrorCode::getFeatureDisabledError());
		}

		if (!$this->isCallModuleAvailable())
		{
			return $result->addError(ErrorCode::getModuleNotInstalledError('call'));
		}

		if (!$this->isSyncPresetEnabled())
		{
			return $result->addError(ErrorCode::getFeatureDisabledError());
		}

		return $result;
	}
}
