<?php

namespace Bitrix\Crm\Component\EntityDetails\TimelineMenuBar\Item;

use Bitrix\Calendar;
use Bitrix\Calendar\Sharing\Link;
use Bitrix\Crm;
use Bitrix\Crm\Component\EntityDetails\TimelineMenuBar\Item;
use Bitrix\Crm\Integration\SmsManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class Sharing extends Item
{
	protected ?array $communications = null;

	public function getId(): string
	{
		return 'sharing';
	}

	public function getName(): string
	{
		return Loc::getMessage('CRM_TIMELINE_SHARING');
	}

	public function isAvailable(): bool
	{
		$isAvailable = Crm\Integration\Calendar\Helper::isSharingCrmAvaible();

		return
			Loader::includeModule('calendar')
			&& $this->getEntityTypeId() === \CCrmOwnerType::Deal
			&& $isAvailable
		;
	}

	public function hasTariffRestrictions(): bool
	{
		return !\Bitrix\Crm\Restriction\RestrictionManager::getCalendarSharingRestriction()->hasPermission();
	}

	public function prepareSettings(): array
	{
		return [
			'config' => [
				'isResponsible' => $this->isResponsible(),
			],
			'isAvailable' => \Bitrix\Crm\Restriction\RestrictionManager::getCalendarSharingRestriction()->hasPermission(),
		];
	}

	public function getConfig(): array
	{
		if (!$this->isAvailable())
		{
			return [];
		}

		$settings = \CCalendar::GetSettings();

		return [
			'config' => [
				'isResponsible' => $this->isResponsible(),
				'link' => $this->prepareLink(),
				'contacts' => $this->getPhoneContacts(),
				'selectedChannelId' => $this->getSelectedChannelId(),
				'communicationChannels' => $this->getCommunicationChannels(),
				'isNotificationsAvailable' => $this->isNotificationsAvailable(),
				'areCommunicationChannelsAvailable' => $this->areCommunicationChannelsAvailable(),
				'calendarSettings' => [
					'weekHolidays' => $settings['week_holidays'],
					'weekStart' => \CCalendar::GetWeekStart(),
					'workTimeStart' => $settings['work_time_start'],
					'workTimeEnd' => $settings['work_time_end'],
				],
			],
			'isAvailable' => \Bitrix\Crm\Restriction\RestrictionManager::getCalendarSharingRestriction()->hasPermission(),
		];
	}

	protected function isResponsible(): bool
	{
		$currentUserId = (new Context())->getUserId();
		return $this->getAssignedId() === $currentUserId;
	}

	protected function getAssignedId(): ?int
	{
		$entityBroker = Container::getInstance()->getEntityBroker($this->getEntityTypeId());
		if (!$entityBroker)
		{
			return null;
		}

		$entity = $entityBroker->getById($this->getEntityId());
		if (!$entity)
		{
			return null;
		}

		return $entity->getAssignedById();
	}

	protected function prepareLink(): array
	{
		if (!Loader::includeModule('calendar'))
		{
			return [];
		}

		$entityId = $this->getEntityId();

		$broker = Container::getInstance()->getEntityBroker($this->getEntityTypeId());
		if (!$broker)
		{
			return [];
		}

		$deal = $broker->getById($this->getEntityId());
		if (!$deal)
		{
			return [];
		}

		$ownerId = $deal->getAssignedById();

		/** @var Link\CrmDealLink $crmDealLink  */
		$crmDealLink = (new Calendar\Sharing\Link\Factory())->getCrmDealLink($entityId, $ownerId);
		if ($crmDealLink === null)
		{
			$crmDealLink = (new Calendar\Sharing\Link\Factory())->createCrmDealLink($ownerId, $entityId);
		}

		return [
			'hash' => $crmDealLink->getHash(),
			'url' => Calendar\Sharing\Helper::getShortUrl($crmDealLink->getUrl()),
			'rule' => (new Link\Rule\Mapper())->convertToArray($crmDealLink->getSharingRule()),
		];
	}

	protected function getPhoneContacts(): array
	{
		$communications = $this->getCommunications();
		return array_map(static function ($communication) {
			return [
				'entityId' => (int)$communication['entityId'],
				'entityTypeId' => (int)$communication['entityTypeId'],
				'name' => $communication['caption'],
				'phone' => $communication['phones'][0]['value'] ?? null,
			];
		}, $communications) ?? [];
	}

	protected function getContact(?int $contactId): ?array
	{
		if (is_null($contactId))
		{
			return null;
		}

		$communications = $this->getCommunications();
		return array_filter($communications, static function ($communication) use ($contactId) {
			return $communication['entityId'] === $contactId;
		})[0];
	}

	public function getCommunications(): array
	{
		if ($this->communications === null)
		{
			$this->communications = SmsManager::getEntityPhoneCommunications($this->getEntityTypeId(), $this->getEntityId());
		}

		return $this->communications ?? [];
	}

	protected function areCommunicationChannelsAvailable(): bool
	{
		$result = false;

		if ($this->getEntityId() > 0 && $this->getEntityTypeId() > 0)
		{
			$entity = new Crm\ItemIdentifier($this->getEntityTypeId(), $this->getEntityId());
			$result = Crm\Integration\Calendar\Notification\NotificationService::canSendMessage($entity)
				|| Crm\Integration\Calendar\Notification\SmsService::canSendMessage($entity)
			;
		}

		return $result;
	}

	protected function getSelectedChannelId(): ?string
	{
		$result = null;

		if ($this->getEntityId() > 0 && $this->getEntityTypeId() > 0)
		{
			$entity = new Crm\ItemIdentifier($this->getEntityTypeId(), $this->getEntityId());
			$result = Crm\Integration\Calendar\Notification\Manager::getOptimalChannelId($entity);
		}

		return $result;
	}

	protected function getCommunicationChannels(): array
	{
		$result = [];

		if ($this->getEntityId() > 0 && $this->getEntityTypeId() > 0)
		{
			$entity = new Crm\ItemIdentifier($this->getEntityTypeId(), $this->getEntityId());
			$result = Crm\Integration\Calendar\Notification\Manager::getCommunicationChannels($entity);
		}

		return $result;
	}

	protected function isNotificationsAvailable(): bool
	{
		return Loader::includeModule('bitrix24') && $this->context->getRegion() === 'ru';
	}
}
