<?php

namespace Bitrix\Crm\Controller\Activity;

use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Service\Container;

class Call extends Base
{
	/**
	 * REST method: crm.activity.call.getTranscript
	 *
	 * Returns call transcription for the given activity.
	 *
	 * Scope: crm
	 * Parameter: activityId — ID of the call activity.
	 *
	 * Success:      result => ['transcription' => string]
	 * No data yet:  result => null  (no error)
	 * Not found:    getNotFoundError
	 * No access:    getAccessDeniedError (AI disabled or no read permission)
	 *
	 * @param int $activityId
	 *
	 * @return array|null
	 */
	public function getTranscriptAction(int $activityId): ?array
	{
		if (!AIManager::isAiCallProcessingEnabled())
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$bindings = \CCrmActivity::GetBindings($activityId);
		if (empty($bindings))
		{
			$this->addError(ErrorCode::getNotFoundError());

			return null;
		}

		$hasAccess = false;
		$itemPermissions = Container::getInstance()->getUserPermissions()->item();
		foreach ($bindings as $binding)
		{
			if ($itemPermissions->canRead((int)$binding['OWNER_TYPE_ID'], (int)$binding['OWNER_ID']))
			{
				$hasAccess = true;
				break;
			}
		}

		if (!$hasAccess)
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$result = JobRepository::getInstance()->getTranscribeCallRecordingResultByActivity($activityId);
		if (
			$result === null
			|| !$result->isSuccess()
			|| $result->isPending()
			|| $result->getPayload() === null
		)
		{
			return null;
		}

		return $result->getPayload()->toArray();
	}
}
