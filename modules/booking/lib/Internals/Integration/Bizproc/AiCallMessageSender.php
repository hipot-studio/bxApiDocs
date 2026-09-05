<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Bizproc;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Integration\Crm\ClientExtractor;
use Bitrix\Booking\Internals\Integration\Crm\CrmBindingsBuilder;
use Bitrix\Booking\Internals\Integration\Pull\PushService;
use Bitrix\Booking\Internals\Repository\BookingMessageRepositoryInterface;
use Bitrix\Booking\Internals\Service\Notifications\AiCallAvailabilityService;
use Bitrix\Booking\Internals\Service\Notifications\Analytics\AiCallAnalytics;
use Bitrix\Booking\Internals\Service\Notifications\BookingMessageStatus;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessage;
use Bitrix\Booking\Internals\Service\AiAssistant\ContextProvider;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\BaseMessageSender;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\MessageSendResult;
use Bitrix\Booking\Internals\Service\Notifications\MessageStatus;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Result\StartResult;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

Loc::loadMessages(
	$_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/booking/lib/Integration/Booking/Message/MessageStatus.php',
);

class AiCallMessageSender extends BaseMessageSender
{
	public const CODE = 'ai_call';

	private const FAILURE_RETRY_AFTER_SECONDS = 60 * 60;

	public function __construct(
		BookingMessageRepositoryInterface $bookingMessageRepository,
		PushService $pushService,
		private readonly ContextProvider $contextProvider,
		private readonly AiAgentTemplateQuery $aiAgentTemplateQuery,
		private readonly ClientExtractor $clientExtractor,
		private readonly CrmBindingsBuilder $crmBindingsBuilder,
		private readonly AiCallAvailabilityService $aiCallAvailabilityService,
		private readonly AiAgentProvider $aiAgentProvider,
		private readonly AiCallAnalytics $aiCallAnalytics,
	)
	{
		parent::__construct($bookingMessageRepository, $pushService);
	}

	public function canUse(): bool
	{
		return $this->aiCallAvailabilityService->isAvailable()
			&& $this->aiAgentProvider->getAiAgentData() !== null;
	}

	public function getCode(): string
	{
		return self::CODE;
	}

	public function getMessageStatus(string $messageId): MessageStatus
	{
		$bookingMessage = $this->bookingMessageRepository->getByExternalId(
			senderCode: $this->getCode(),
			externalId: $messageId,
		);
		if (!$bookingMessage)
		{
			return MessageStatus::failure(Loc::getMessage('MESSAGE_STATUS_AI_CALL_NOT_FOUND'));
		}

		$bookingMessageStatus = $bookingMessage->getStatus();
		if (!$bookingMessageStatus)
		{
			return MessageStatus::failure(Loc::getMessage('MESSAGE_STATUS_AI_CALL_NOT_FOUND'));
		}

		if ($bookingMessageStatus === BookingMessageStatus::Pending)
		{
			return MessageStatus::success(Loc::getMessage('MESSAGE_STATUS_AI_CALL_PENDING'));
		}

		if ($bookingMessageStatus === BookingMessageStatus::Success)
		{
			return MessageStatus::success(Loc::getMessage('MESSAGE_STATUS_AI_CALL_SUCCESS'));
		}

		if ($bookingMessageStatus === BookingMessageStatus::Failed)
		{
			return MessageStatus::failure(Loc::getMessage('MESSAGE_STATUS_AI_CALL_FAILED'));
		}

		if ($bookingMessageStatus === BookingMessageStatus::Cancelled)
		{
			return MessageStatus::failure(Loc::getMessage('MESSAGE_STATUS_AI_CALL_CANCELLED'));
		}

		return MessageStatus::failure(Loc::getMessage('MESSAGE_STATUS_AI_CALL_NOT_FOUND'));
	}

	public function getInitialMessageStatus(): BookingMessageStatus
	{
		return BookingMessageStatus::Pending;
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

	protected function doSend(BookingMessage $bookingMessage, Booking $booking): MessageSendResult
	{
		$result = new MessageSendResult();

		$phoneNumber = $this->getClientPhoneNumber($booking);
		if ($phoneNumber === null)
		{
			return $result->addError(new Error('Client phone number not found'));
		}

		if (
			!Loader::includeModule('bizproc')
			|| !class_exists(Starter::class)
			|| !Starter::isEnabled()
		)
		{
			return $result->addError(new Error('Bizproc Starter is not available'));
		}

		$startResult = Starter::getByScenario(Scenario::onEvent)
			->setContext(new ContextDto('booking'))
			->addEvent(
				'BookingAiCallTrigger',
				[],
				$this->buildEventParams($bookingMessage, $booking, $phoneNumber),
			)
			->start()
		;

		return $this->processStartResult($startResult);
	}

	private function buildEventParams(
		BookingMessage $bookingMessage,
		Booking $booking,
		string $phoneNumber,
	): array
	{
		$notificationType = $bookingMessage->getNotificationType()->value;
		$contextData = $this->contextProvider->getContext($booking->getId());

		$primaryClient = $booking->getPrimaryClient();

		return [
			'Scenario' => $notificationType,
			'PhoneNumber' => $phoneNumber,
			'BookingMessageId' => $bookingMessage->getId(),
			'PromptContext' => $contextData ? Json::encode($contextData) : '',
			'PromptScenario' => 'booking.notification.' . $notificationType,
			'CrmEntityType' => $primaryClient ? $this->clientExtractor->getCrmEntityType($primaryClient) : null,
			'CrmEntityId' => $primaryClient ? $this->clientExtractor->getCrmEntityId($primaryClient) : null,
			'CrmBindings' => $this->crmBindingsBuilder->getBindingsFromBooking($booking),
			'Mcp' => [
				'ToolSetId' => 'booking.tool_set.booking',
				'Context' => [
					'senderCode' => $this->getCode(),
				],
				'UserId' => $this->aiAgentTemplateQuery->findUserTemplateOwnerUserId(),
			],
		];
	}

	public function markSuccess(int $bookingMessageId): void
	{
		$bookingMessage = $this->bookingMessageRepository->getById($bookingMessageId);
		if ($bookingMessage === null)
		{
			return;
		}

		$bookingMessage->setStatus(BookingMessageStatus::Success);
		$this->bookingMessageRepository->save($bookingMessage);

		$this->aiCallAnalytics->sendSuccess($bookingMessage);
	}

	public function markFailed(int $bookingMessageId, int $retryAfterSeconds = self::FAILURE_RETRY_AFTER_SECONDS): void
	{
		$bookingMessage = $this->bookingMessageRepository->getById($bookingMessageId);
		if ($bookingMessage === null)
		{
			return;
		}

		$bookingMessage
			->setStatus(BookingMessageStatus::Failed)
			->setNextRetryAt(time() + $retryAfterSeconds)
		;
		$this->bookingMessageRepository->save($bookingMessage);

		$this->aiCallAnalytics->sendError($bookingMessage);
	}

	private function processStartResult(StartResult $startResult): MessageSendResult
	{
		$result = new MessageSendResult();

		$workflowIds = $startResult->getWorkflowIds();
		if (!empty($workflowIds))
		{
			return $result->setId($workflowIds[0]);
		}

		foreach ($startResult->getErrors() as $error)
		{
			$result->addError($error);
		}

		if ($result->isSuccess())
		{
			$result->addError(new Error('No workflow started'));
		}

		return $result;
	}

	private function getClientPhoneNumber(Booking $booking): ?string
	{
		$primaryClient = $booking->getPrimaryClient();
		if ($primaryClient === null)
		{
			return null;
		}

		$phones = $primaryClient->getData()['phones'] ?? [];

		return $phones[0] ?? null;
	}
}
