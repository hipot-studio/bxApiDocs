<?php

namespace Bitrix\Crm\Copilot\CallAssessment;

use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Enum\CallType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\ClientType;
use Bitrix\Crm\Integration\VoxImplant\Call;
use Bitrix\Crm\Integration\VoxImplantManager;
use Bitrix\Crm\MultiValueStoreService;
use Bitrix\Crm\Service\Container;
use CCrmActivityDirection;
use CCrmActivityType;
use CCrmOwnerType;

final class ItemFactory
{
	/**
	 * Upper bound of the candidate list. The settings go into the prompt of the script selector, so the
	 * list has to be bounded; the bound is high enough that a portal reaches it only with an unusable
	 * number of scripts for one call.
	 */
	private const CANDIDATES_LIMIT = 50;

	/**
	 * @param bool $requireScoringCriteria limits the selection to settings the V2 script selector accepts:
	 *        it takes only settings with criteria, so without the same condition here the newest matching
	 *        setting can hide a ready one behind it. A setting pinned to the call is exempt - it is an
	 *        explicit choice of the user and must not be silently replaced by another one.
	 */
	public static function getByActivityId(
		int $activityId,
		bool $requireScoringCriteria = false,
	): ?CallAssessmentItem
	{
		$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
		if (!$activity)
		{
			return null;
		}

		if ((int)$activity['TYPE_ID'] !== CCrmActivityType::Call)
		{
			return null;
		}

		$originId = $activity['ORIGIN_ID'] ?? '';
		if (VoxImplantManager::isVoxImplantOriginId($originId))
		{
			$callId = VoxImplantManager::extractCallIdFromOriginId($originId);
			$savedAssessmentItemByCallId = self::getSavedAssessmentItemByCallId($callId);
			if ($savedAssessmentItemByCallId)
			{
				return $savedAssessmentItemByCallId;
			}
		}

		$clientType = (new AssessmentClientTypeResolver())->resolveByActivityId($activityId);
		if (!$clientType)
		{
			return null;
		}

		return self::getAssessmentByClientAndCallType(
			$clientType,
			self::getCallType((int)$activity['DIRECTION']),
			$requireScoringCriteria,
		);
	}

	/**
	 * Every setting admissible for this call, in the order of the single lookup - so the head of the list
	 * is what getByActivityId() returns with the same arguments.
	 *
	 * This is what the V2 script selector chooses between. Handing it the single pick instead left it no
	 * choice at all and the script of a call was decided by the freshest UPDATED_AT of the most specific
	 * client type. The list keeps the gates on the CRM side and the choice within them on the model side.
	 *
	 * @return int[]
	 */
	public static function getCandidateIdsByActivityId(
		int $activityId,
		bool $requireScoringCriteria = false,
	): array
	{
		$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
		if (!$activity || (int)$activity['TYPE_ID'] !== CCrmActivityType::Call)
		{
			return [];
		}

		$originId = $activity['ORIGIN_ID'] ?? '';
		if (VoxImplantManager::isVoxImplantOriginId($originId))
		{
			$callId = VoxImplantManager::extractCallIdFromOriginId($originId);
			$savedAssessmentItemByCallId = self::getSavedAssessmentItemByCallId($callId);
			if ($savedAssessmentItemByCallId)
			{
				// the setting pinned to the call is an explicit choice of the user: it is the only candidate,
				// and the selector must not be given the freedom to replace it
				return [(int)$savedAssessmentItemByCallId->getId()];
			}
		}

		$clientType = (new AssessmentClientTypeResolver())->resolveByActivityId($activityId);
		if (!$clientType)
		{
			return [];
		}

		return self::getAssessmentIdsByClientAndCallType(
			$clientType,
			self::getCallType((int)$activity['DIRECTION']),
			$requireScoringCriteria,
		);
	}

	public static function getByCallId(string $callId): ?CallAssessmentItem
	{
		$savedAssessmentItemByCallId = self::getSavedAssessmentItemByCallId($callId);
		if ($savedAssessmentItemByCallId)
		{
			return $savedAssessmentItemByCallId;
		}

		$voximplantCall = new Call($callId);
		$callCrmEntities = $voximplantCall->getCrmEntities();
		if (!$callCrmEntities)
		{
			return null;
		}

		$callDirection = $voximplantCall->getDirection();
		if (!CCrmActivityDirection::IsDefined($callDirection))
		{
			return null;
		}

		foreach ($callCrmEntities as $callCrmEntity)
		{
			$assessmentClientTypeResolver = new AssessmentClientTypeResolver();
			$availableEntityTypeIds = [
				CCrmOwnerType::Lead,
				CCrmOwnerType::Contact,
				CCrmOwnerType::Company
			];
			if (in_array($callCrmEntity->getEntityTypeId(), $availableEntityTypeIds, true))
			{
				$clientType = $assessmentClientTypeResolver->resolveByIdentifier($callCrmEntity);
				if (!$clientType)
				{
					return null;
				}

				return self::getAssessmentByClientAndCallType($clientType, self::getCallType($callDirection));
			}
		}

		return null;
	}

	private static function getSavedAssessmentItemByCallId(string $callId): ?CallAssessmentItem
	{
		$originCallId = VoxImplantManager::insertPrefix($callId);
		$callAssessmentItemId = MultiValueStoreService::getInstance()->getFirstValue($originCallId);
		if (!$callAssessmentItemId)
		{
			return null;
		}

		$callAssessmentItem = CopilotCallAssessmentController::getInstance()->getById($callAssessmentItemId);
		if (!$callAssessmentItem)
		{
			return null;
		}

		if (!$callAssessmentItem->getIsEnabled())
		{
			return null;
		}

		return CallAssessmentItem::createFromEntity($callAssessmentItem);
	}

	private static function getAssessmentByClientAndCallType(
		ClientType $assessmentClientType,
		CallType $callType,
		bool $requireScoringCriteria = false,
	): ?CallAssessmentItem
	{
		$assessment = CopilotCallAssessmentController::getInstance()->getList([
			'filter' => self::buildClientAndCallTypeFilter($assessmentClientType, $callType, $requireScoringCriteria),
			'limit' => 1,
			'order' => self::getSelectionOrder(),
		])->current();

		return $assessment
			? CallAssessmentItem::createFromEntity($assessment)
			: null
		;
	}

	/**
	 * @return int[]
	 */
	private static function getAssessmentIdsByClientAndCallType(
		ClientType $assessmentClientType,
		CallType $callType,
		bool $requireScoringCriteria = false,
	): array
	{
		$assessments = CopilotCallAssessmentController::getInstance()->getList([
			'select' => ['ID'],
			'filter' => self::buildClientAndCallTypeFilter($assessmentClientType, $callType, $requireScoringCriteria),
			// the default limit of the controller is 10, and a portal keeps more scripts than that
			'limit' => self::CANDIDATES_LIMIT,
			'order' => self::getSelectionOrder(),
		]);

		$ids = [];
		foreach ($assessments as $assessment)
		{
			// a setting of both a specific client type and the ANY fallback comes back once per join row
			$ids[] = (int)$assessment->getId();
		}

		return array_values(array_unique($ids));
	}

	private static function buildClientAndCallTypeFilter(
		ClientType $assessmentClientType,
		CallType $callType,
		bool $requireScoringCriteria,
	): array
	{
		$filter = [
			'=CALL_TYPE' => [CallType::ALL->value, $callType->value],
			'=CLIENT_TYPES.CLIENT_TYPE_ID' => [$assessmentClientType->value, ClientType::ANY->value],
			'=IS_ENABLED' => true,
		];

		if ($requireScoringCriteria)
		{
			$filter['!=CRITERIA.ID'] = null;
		}

		return $filter;
	}

	/**
	 * A script bound to the exact client type must win over the ANY fallback even if ANY was updated later:
	 * ClientType::ANY has the highest value, so specific types sort first, then the freshest within each group.
	 */
	private static function getSelectionOrder(): array
	{
		return [
			'CLIENT_TYPES.CLIENT_TYPE_ID' => 'ASC',
			'UPDATED_AT' => 'DESC',
		];
	}

	private static function getCallType(int $callDirection): CallType
	{
		return (
			$callDirection === CCrmActivityDirection::Incoming
				? CallType::INCOMING
				: CallType::OUTGOING
		);
	}
}
