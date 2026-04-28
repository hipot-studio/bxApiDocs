<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\Client\Client;
use Bitrix\Booking\Entity\ExternalData\ExternalDataCollection;
use Bitrix\Booking\Internals\Integration\Notifications\TemplateRepository;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\BookingDataExtractor;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\MessageSendResult;
use Bitrix\Booking\Internals\Service\Notifications\MessageStatus;
use Bitrix\Booking\Internals\Service\Notifications\NotificationTemplateType;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Booking\Internals\Repository\BookingMessageRepositoryInterface;
use Bitrix\Booking\Provider\BookingProvider;
use Bitrix\Booking\Provider\NotificationsAvailabilityProvider;
use Bitrix\Booking\Provider\NotificationsLanguageProvider;
use Bitrix\Crm\Dto\Booking\Booking\BookingFieldsMapper;
use Bitrix\Crm\Dto\Booking\Message\Message;
use Bitrix\Crm\Integration\NotificationsManager;
use Bitrix\Crm\Item\Deal;
use Bitrix\Crm\MessageSender\Channel\ChannelRepository;
use Bitrix\Crm\MessageSender\Channel\Correspondents\To;
use Bitrix\Crm\Multifield\Value;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\Booking\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use CCrmOwnerType;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\MessageSender\SendFacilitator;
use Bitrix\Notifications;
use Bitrix\Crm\Multifield\Type\Phone;
use Bitrix\Booking\Internals\Service\LicenseChecker;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\BaseMessageSender;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/booking/lib/Integration/Booking/Message/MessageStatus.php');

class MessageSender extends BaseMessageSender
{
	public function __construct(
		private readonly DealDataProvider $dealDataProvider,
		private readonly ExternalDataItemExtractor $externalDataExtractor,
		private readonly NotificationsLanguageProvider $notificationsLanguageProvider,
		BookingMessageRepositoryInterface $bookingMessageRepository,
		private readonly BookingDataExtractor $bookingDataExtractor,
		private readonly LicenseChecker $licenseChecker,
	)
	{
		parent::__construct($bookingMessageRepository);
	}

	public function getCode(): string
	{
		if (!Loader::includeModule('crm'))
		{
			return '';
		}

		return NotificationsManager::getSenderCode();
	}

	protected function doSend(Booking $booking, NotificationType $notificationType): MessageSendResult
	{
		$result = new MessageSendResult();

		if (!Loader::includeModule('crm'))
		{
			return $result->addError(new Error('CRM module is not available'));
		}

		$primaryClient = $booking->getPrimaryClient();
		if (!$primaryClient)
		{
			return $result->addError(new Error('Primary client has not been found'));
		}

		$templateCode = $this->getTemplateCode($booking, $notificationType);
		if (!$templateCode)
		{
			return $result->addError(new Error('Template code could not be resolved'));
		}

		$placeholders = $this->getDefaultPlaceholders($booking);

		$senderCode = $channelId = NotificationsManager::getSenderCode();

		$channelItemIdentifier = self::createItemIdentifierForChannel(
			$primaryClient,
			$this->getDealFromExternalDataCollection($booking->getExternalDataCollection())
		);
		$channel = ChannelRepository::create($channelItemIdentifier)->getById($senderCode, $channelId);

		if (!$channel)
		{
			return $result->addError(new Error('Channel has not been found'));
		}

		$facilitator = (new SendFacilitator\Notifications($channel))
			->setTo($this->makeTo($channelItemIdentifier, $primaryClient))
			->setTemplateCode($templateCode)
			->setPlaceholders($placeholders)
			->setLanguageId($this->notificationsLanguageProvider->getLanguageId())
		;

		$sendResult = $facilitator->send();

		$messageId = isset($sendResult->getData()['ID']) ? (int)$sendResult->getData()['ID'] : null;
		if (!$messageId)
		{
			return $result->addError(new Error('Message can not be sent'));
		}

		return $result->setId((string)$messageId);
	}

	public function getMessageStatus(string $messageId): MessageStatus
	{
		if (!Loader::includeModule('crm'))
		{
			return MessageStatus::failure(Loc::getMessage('MESSAGE_STATUS_FAILED'));
		}

		$messageInfo = NotificationsManager::getMessageByInfoId((int)$messageId);

		$status = $messageInfo['MESSAGE']['STATUS'] ?? '';
		if ($status === '')
		{
			return MessageStatus::success(Loc::getMessage('MESSAGE_STATUS_SENDING'));
		}

		$sentStatuses = [
			Notifications\MessageStatus::ENQUEUED_LOCAL,
			Notifications\MessageStatus::ENQUEUED,
			Notifications\MessageStatus::SENT,
			Notifications\MessageStatus::IN_DELIVERY,
		];
		if (in_array($status, $sentStatuses, true))
		{
			return MessageStatus::success(Loc::getMessage('MESSAGE_STATUS_SENT'));
		}

		if ($status === Notifications\MessageStatus::DELIVERED)
		{
			return MessageStatus::success(Loc::getMessage('MESSAGE_STATUS_DELIVERED'));
		}

		if ($status === Notifications\MessageStatus::READ)
		{
			return MessageStatus::success(Loc::getMessage('MESSAGE_STATUS_READ'));
		}

		return MessageStatus::failure(Loc::getMessage('MESSAGE_STATUS_FAILED'));
	}

	public function canUse(): bool
	{
		if (!Loader::includeModule('crm'))
		{
			return false;
		}

		if (!$this->licenseChecker->isPaidOrBox())
		{
			return false;
		}

		return (
			NotificationsAvailabilityProvider::isAvailable()
			&& NotificationsManager::canUse()
		);
	}

	public function getSupportedNotificationTypes(): array
	{
		return [
			NotificationType::Info,
			NotificationType::Confirmation,
			NotificationType::Reminder,
			NotificationType::Delayed,
		];
	}

	public function onMessageStatusUpdate(Event $event): void
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		$id = (string)($event->getParameter('ID') ?? '');
		if ($id === '')
		{
			return;
		}

		$bookingMessage = $this->bookingMessageRepository->getByExternalId(
			senderCode: $this->getCode(),
			externalId: $id,
		);
		if (!$bookingMessage)
		{
			return;
		}

		$messageStatus = (string)$event->getParameter('STATUS');
		$messageInfo = NotificationsManager::getMessageByInfoId((int)$id);
		if (self::isMessageStatusUpdateDuplicate($messageStatus, $messageInfo['HISTORY_ITEMS'] ?? []))
		{
			return;
		}

		try
		{
			$message = Message::mapFromArray([
				'type' => $bookingMessage->getNotificationType()->value,
				'status' => $messageStatus,
				'timestamp' => time(),
			]);
		}
		catch (\Throwable)
		{
			return;
		}

		$booking = (new BookingProvider())->getById(
			(int)CurrentUser::get()->getId(),
			$bookingMessage->getBookingId(),
		)?->toArray();

		// booking may be deleted already
		if (!$booking)
		{
			return;
		}

		$bookingFields = BookingFieldsMapper::mapFromBookingArray(booking: $booking);

		if ($message->isSupported())
		{
			(new Controller())->onMessageStatusUpdate(
				$bookingFields,
				$message,
				$messageInfo,
			);
		}

		if ($message->isMeaning())
		{
			\Bitrix\Crm\Activity\Provider\Booking\Booking::onBookingMessageUpdated(
				booking: $bookingFields,
				message: $message,
			);
		}
	}

	private static function isMessageStatusUpdateDuplicate(string $messageStatus, array $historyItems): bool
	{
		$historyRecordsCnt = 0;

		foreach ($historyItems as $historyItem)
		{
			if ($historyItem['STATUS'] === $messageStatus)
			{
				$historyRecordsCnt++;
			}

			if ($historyRecordsCnt > 1)
			{
				return true;
			}
		}

		return false;
	}

	private function makeTo(ItemIdentifier $rootSource, Client $primaryClient): To
	{
		$defaultTo = new To(
			$rootSource,
			new ItemIdentifier(
				CCrmOwnerType::ResolveID($primaryClient->getType()?->getCode()),
				$primaryClient->getId()
			),
			new Value()
		);

		$primaryClientTypeCode = $primaryClient->getType()?->getCode();
		if (!$primaryClientTypeCode)
		{
			return $defaultTo;
		}

		$factory = Container::getInstance()->getFactory(
			\CCrmOwnerType::ResolveID($primaryClientTypeCode)
		);
		if (!$factory)
		{
			return $defaultTo;
		}

		$item = $factory->getItem($primaryClient->getId());
		if (!$item)
		{
			return $defaultTo;
		}

		$values = $item->getFm()->filterByType(Phone::ID)->getAll();
		if (empty($values))
		{
			return $defaultTo;
		}

		return new To(
			$rootSource,
			new ItemIdentifier(
				CCrmOwnerType::ResolveID($primaryClient->getType()?->getCode()),
				$primaryClient->getId()
			),
			current($values)
		);
	}

	private function createItemIdentifierForChannel(
		Client $primaryClient,
		Deal|null $deal
	): ItemIdentifier
	{
		if ($deal)
		{
			if (
				(
					$primaryClient->getType()?->getCode() === CCrmOwnerType::CompanyName
					&& $deal->getCompany()?->getId() === $primaryClient->getId()
				)
				|| (
					$primaryClient->getType()?->getCode() === CCrmOwnerType::ContactName
					&& in_array($primaryClient->getId(), $deal->getContactIds(), true)
				)
			)
			{
				return new ItemIdentifier(
					CCrmOwnerType::Deal,
					$deal->getId()
				);
			}
		}

		return new ItemIdentifier(
			CCrmOwnerType::ResolveID($primaryClient->getType()?->getCode()),
			$primaryClient->getId()
		);
	}

	private function getDealFromExternalDataCollection(ExternalDataCollection $externalDataCollection): Deal|null
	{
		$deals = array_values(
			$this->dealDataProvider->getByIds(
				$this->externalDataExtractor->getDealIdsFromCollections([$externalDataCollection])
			)
		);

		return empty($deals) ? null : $deals[0];
	}

	private function getDefaultPlaceholders(Booking $booking): array
	{
		return [
			'DATE_FROM' => $this->bookingDataExtractor->getDateFrom($booking),
			'DATE_TO' => $this->bookingDataExtractor->getDateTo($booking),
			'DATE_TIME_FROM' => $this->bookingDataExtractor->getDateTimeFrom($booking),
			'DATE_TIME_TO' => $this->bookingDataExtractor->getDateTimeTo($booking),
			'RESOURCE_TYPE_NAME' => $this->bookingDataExtractor->getResourceTypeName($booking),
			'RESOURCE_NAME' => $this->bookingDataExtractor->getResourceName($booking),
			'CLIENT_NAME' => $this->bookingDataExtractor->getClientName($booking),
			'MANAGER_NAME' => $this->bookingDataExtractor->getManagerName($booking),
			'COMPANY_NAME' => $this->bookingDataExtractor->getCompanyName(),
			'CONFIRMATION_LINK' => $this->bookingDataExtractor->getConfirmationLink($booking),
			'DELAYED_CONFIRMATION_LINK' => $this->bookingDataExtractor->getDelayedConfirmationLink($booking),
			'FEEDBACK_LINK' => $this->bookingDataExtractor->getFeedbackLink(),
			'SOME_TEXT' => $this->bookingDataExtractor->getServices($booking),
		];
	}

	private function getTemplateCode(
		Booking $booking,
		NotificationType $notificationType,
	): string|null
	{
		$resource = $this->bookingDataExtractor->getResource($booking);
		if (!$resource)
		{
			return null;
		}

		$templateTypeValue = match ($notificationType)
		{
			NotificationType::Info => $resource->getTemplateTypeInfo(),
			NotificationType::Confirmation => $resource->getTemplateTypeConfirmation(),
			NotificationType::Reminder => $resource->getTemplateTypeReminder(),
			NotificationType::Delayed => $resource->getTemplateTypeDelayed(),
			NotificationType::Feedback => $resource->getTemplateTypeFeedback(),
		};

		return TemplateRepository::getTemplateCode(
			$notificationType,
			NotificationTemplateType::from($templateTypeValue)
		);
	}
}
