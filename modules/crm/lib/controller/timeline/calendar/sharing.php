<?php

namespace Bitrix\Crm\Controller\Timeline\Calendar;

use Bitrix\Crm\Component\EntityDetails\TimelineMenuBar;
use Bitrix\Crm\Integration\Calendar\EventData;
use Bitrix\Crm\Integration\Calendar\Helper;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;

class Sharing extends Controller
{
	public function sendLinkAction(
		int $contactId,
		int $contactTypeId,
		int $ownerId,
		int $ownerTypeId,
		string $channelId,
		string $senderId,
		array $ruleArray,
		array $memberIds = [],
	): bool
	{
		if (!$this->canUpdateDeal($ownerTypeId, $ownerId))
		{
			$this->addError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			return false;
		}

		$sendingResult = Helper::getInstance()->sendLinkToClient($ownerId, $contactId, $contactTypeId, $channelId, $senderId, $ruleArray, $memberIds);
		if ($sendingResult->getErrors())
		{
			$this->addErrors($sendingResult->getErrors());

			return false;
		}

		$linkHash = $sendingResult->getData()['linkHash'];
		$timelineResult = Helper::getInstance()->addTimelineEntry($linkHash, EventData::SHARING_ON_INVITATION_SENT);
		if (!$timelineResult)
		{
			$this->addError(new Error('Timeline entry not created'));

			return false;
		}

		return true;
	}

	public function generateJointSharingLinkAction(
		int $entityTypeId,
		int $entityId,
		array $memberIds,
	): array
	{
		if (!$this->canUpdateDeal($entityTypeId, $entityId))
		{
			$this->addError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			return [];
		}

		$broker = Container::getInstance()->getEntityBroker(\CCrmOwnerType::Deal);
		if (!$broker)
		{
			return [];
		}

		$deal = $broker->getById($entityId);
		if (!$deal)
		{
			return [];
		}

		$ownerId = $deal->getAssignedById();

		$result = Helper::getInstance()->generateJointLink($entityId, $ownerId, $memberIds);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return $result->getData();
	}

	public function onLinkCopiedAction(string $linkHash, int $ownerId, int $ownerTypeId): bool
	{
		if (
			!$this->canUpdateExistingItem($ownerTypeId, $ownerId)
			|| $ownerTypeId !== \CCrmOwnerType::Deal
			|| Helper::getInstance()->getCrmDealIdByLinkHash($linkHash) !== $ownerId
		)
		{
			$this->addError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			return false;
		}

		$timelineResult = Helper::getInstance()->addTimelineEntry($linkHash, EventData::SHARING_ON_LINK_COPIED);
		if (!$timelineResult)
		{
			$this->addError(new Error('Timeline entry not created'));
		}

		return true;
	}

	public function onRuleUpdatedAction(string $linkHash, int $ownerId, int $ownerTypeId): bool
	{
		if (
			!$this->canUpdateExistingItem($ownerTypeId, $ownerId)
			|| $ownerTypeId !== \CCrmOwnerType::Deal
			|| Helper::getInstance()->getCrmDealIdByLinkHash($linkHash) !== $ownerId
		)
		{
			$this->addError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			return false;
		}

		$timelineResult = Helper::getInstance()->addTimelineEntry($linkHash, EventData::SHARING_ON_RULE_UPDATED);
		if (!$timelineResult)
		{
			$this->addError(new Error('Timeline entry not created'));
		}

		return true;
	}

	public function getConferenceChatIdAction(int $eventId, int $ownerId, int $ownerTypeId)
	{
		if (
			!$this->canUpdateExistingItem($ownerTypeId, $ownerId)
			|| $ownerTypeId !== \CCrmOwnerType::Deal
			|| Helper::getInstance()->getCrmDealIdByEventId($eventId) !== $ownerId
		)
		{
			$this->addError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			return false;
		}

		$result = Helper::getInstance()->getConferenceChatId($eventId);
		if (!$result)
		{
			$this->addError(new Error('Error while trying to get chat id'));
		}

		return $result;
	}

	public function completeWithStatusAction(int $activityId, int $ownerTypeId, int $ownerId, string $status)
	{
		if (
			!$this->canUpdateExistingItem($ownerTypeId, $ownerId)
			|| !$this->hasActivityBinding($activityId, $ownerTypeId, $ownerId)
		)
		{
			$this->addError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			return false;
		}

		$result = Helper::getInstance()->completeActivityWithStatus($activityId, $ownerTypeId, $ownerId, $status);
		if (!$result)
		{
			$this->addError(new Error('Error while trying to complete activity'));
		}

		return $result;
	}

	public function cancelMeetingAction(int $eventId, int $ownerId, int $ownerTypeId)
	{
		if (
			!$this->canUpdateExistingItem($ownerTypeId, $ownerId)
			|| $ownerTypeId !== \CCrmOwnerType::Deal
			|| Helper::getInstance()->getCrmDealIdByEventId($eventId) !== $ownerId
		)
		{
			$this->addError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			return false;
		}

		$result = Helper::getInstance()->cancelMeeting($eventId);
		if (!$result)
		{
			$this->addError(new Error('Error while trying to cancel meeting'));
		}

		return $result;
	}
	
	public function getConfigAction(int $entityTypeId, int $entityId): array
	{
		$result = [];
		if (!\Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->item()->canUpdate($entityTypeId, $entityId))
		{
			$this->addError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			return $result;
		}

		if (!\Bitrix\Main\Loader::includeModule('calendar'))
		{
			$this->addError(new Error('Calendar module not found'));

			return $result;
		}

		$context = new TimelineMenuBar\Context($entityTypeId, $entityId);

		$sharing = new TimelineMenuBar\Item\Sharing($context);
		$result = $sharing->getConfig();
		$result['smsConfig'] = $this->getSmsConfig();

		return $result;
	}

	private function getSmsConfig(): array
	{
		return [
			'contactCenterUrl' => \Bitrix\Crm\Service\Container::getInstance()->getRouter()->getContactCenterUrl(),
		];
	}

	private function canUpdateDeal(int $entityTypeId, int $entityId): bool
	{
		if ($entityTypeId !== \CCrmOwnerType::Deal)
		{
			return false;
		}

		return Container::getInstance()->getUserPermissions()->item()->canUpdate(\CCrmOwnerType::Deal, $entityId);
	}

	private function canUpdateExistingItem(int $ownerTypeId, int $ownerId): bool
	{
		if ($ownerId <= 0 || !\CCrmOwnerType::IsDefined($ownerTypeId))
		{
			return false;
		}

		$container = Container::getInstance();

		return $container->getUserPermissions()->item()->canUpdate($ownerTypeId, $ownerId)
			&& $container->getFactory($ownerTypeId)?->getItem($ownerId, ['ID']) !== null
		;
	}

	private function hasActivityBinding(int $activityId, int $ownerTypeId, int $ownerId): bool
	{
		foreach (Helper::getInstance()->getActivityBindings($activityId) as $binding)
		{
			if ((int)$binding['OWNER_TYPE_ID'] === $ownerTypeId && (int)$binding['OWNER_ID'] === $ownerId)
			{
				return true;
			}
		}

		return false;
	}
}
