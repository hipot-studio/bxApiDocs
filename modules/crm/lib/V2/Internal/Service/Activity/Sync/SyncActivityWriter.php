<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Sync;

use Bitrix\Crm\Activity\Provider\Sync;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * Not final: a test seam.
 *
 * @internal
 */
class SyncActivityWriter
{
	/**
	 * @return Result data on success: ['activityId' => int]
	 */
	public function add(ItemIdentifier $owner, int $userId): Result
	{
		$result = new Result();

		$fields = [
			'TYPE_ID' => \CCrmActivityType::Provider,
			'PROVIDER_ID' => Sync::PROVIDER_ID,
			'PROVIDER_TYPE_ID' => Sync::TYPE_SYNC,
			'DIRECTION' => \CCrmActivityDirection::Incoming,
			'IS_INCOMING_CHANNEL' => 'Y',
			'COMPLETED' => 'N',
			'SUBJECT' => (string)Loc::getMessage('CRM_SYNC_ACTIVITY_SUBJECT'),
			'AUTHOR_ID' => $userId,
			'RESPONSIBLE_ID' => $userId,
			'BINDINGS' => [
				[
					'OWNER_TYPE_ID' => $owner->getEntityTypeId(),
					'OWNER_ID' => $owner->getEntityId(),
				],
			],
		];

		$activityId = \CCrmActivity::Add($fields, false, true, ['CURRENT_USER' => $userId]);
		if (!$activityId)
		{
			return $result->addError($this->makeLegacyError());
		}

		return $result->setData(['activityId' => (int)$activityId]);
	}

	public function setChatId(int $activityId, int $chatId): Result
	{
		$result = new Result();

		if (!\CCrmActivity::Update($activityId, ['ASSOCIATED_ENTITY_ID' => $chatId], false))
		{
			$result->addError($this->makeLegacyError());
		}

		return $result;
	}

	public function delete(int $activityId): Result
	{
		$result = new Result();

		$errorCount = \CCrmActivity::GetErrorCount();
		if (!\CCrmActivity::Delete($activityId, false))
		{
			$result->addError($this->makeLegacyError($errorCount));
		}

		return $result;
	}

	/**
	 * Add() and Update() clear the errors of CCrmActivity before they check the fields, Delete() does not and
	 * registers none on a veto of an event handler: for it only the messages added after $errorCountBefore count.
	 */
	private function makeLegacyError(int $errorCountBefore = 0): Error
	{
		$messages = array_slice(\CCrmActivity::GetErrorMessages(), $errorCountBefore);
		if (empty($messages))
		{
			return ErrorCode::getGeneralError();
		}

		return new Error(implode('; ', $messages));
	}
}
