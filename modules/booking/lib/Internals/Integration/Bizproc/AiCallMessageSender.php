<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Bizproc;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Repository\BookingMessageRepositoryInterface;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\BaseMessageSender;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\BookingDataExtractor;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\MessageSendResult;
use Bitrix\Booking\Internals\Service\Notifications\MessageStatus;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/booking/lib/Integration/Booking/Message/MessageStatus.php');

class AiCallMessageSender extends BaseMessageSender
{
	public function __construct(
		BookingMessageRepositoryInterface $bookingMessageRepository,
		private readonly BookingDataExtractor $bookingDataExtractor,
	)
	{
		parent::__construct($bookingMessageRepository);
	}

	protected function doSend(Booking $booking, NotificationType $notificationType): MessageSendResult
	{
		//@todo
		return (new MessageSendResult())->setId((string)random_int(1, 1000));
	}

	public function canUse(): bool
	{
		$licenseRegion = Application::getInstance()->getLicense()->getRegion() ?? 'en';

		return (
			ModuleManager::isModuleInstalled('bizproc')
			&& ModuleManager::isModuleInstalled('voximplant')
			&& $licenseRegion === 'ru'
		);
	}

	public function getCode(): string
	{
		return 'ai_call';
	}

	public function getMessageStatus(string $messageId): MessageStatus
	{
		return MessageStatus::success(Loc::getMessage('MESSAGE_STATUS_AI_CALL_QUEUED'));
	}

	public function getSupportedNotificationTypes(): array
	{
		return [
			NotificationType::Confirmation,
			NotificationType::Reminder,
			NotificationType::Delayed,
			NotificationType::Cancellation,
		];
	}
}
