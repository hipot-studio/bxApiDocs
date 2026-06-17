<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Integration\Crm\CrmBindingsBuilder;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ORM\BookingMessageRepository;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessage;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;

abstract class BaseBookingTool extends ToolContract
{
	protected BookingRepositoryInterface $bookingRepository;
	protected BookingMessageRepository $bookingMessageRepository;
	protected CrmBindingsBuilder $crmBindingsBuilder;

	protected BookingMessage|null $bookingMessage = null;
	protected Booking|null $contextBooking = null;

	public function __construct(TracedLogger $tracedLogger)
	{
		parent::__construct($tracedLogger);

		$this->bookingRepository = Container::getBookingRepository();
		$this->bookingMessageRepository = Container::getBookingMessageRepository();
		$this->crmBindingsBuilder = Container::getCrmBindingsBuilder();
	}

	protected function createSuccessResponse(string $message, array $crmBindings = [], array $data = []): array
	{
		return [
			'success' => true,
			'message' => $message,
			'crmBindings' => $crmBindings,
			'data' => $data,
		];
	}

	protected function createFailureResponse(string $message): array
	{
		return [
			'success' => false,
			'message' => "Failed to execute the tool '{$this->getName()}': {$message}",
			'crmBindings' => [],
			'data' => [],
		];
	}

	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		return true;
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$context = $args['_context'] ?? [];
		$senderCode = isset($context['senderCode']) ? (string)$context['senderCode'] : '';
		$externalMessageId = isset($context['workflowInstanceId']) ? (string)$context['workflowInstanceId'] : '';
		if ($senderCode === '' || $externalMessageId === '')
		{
			return $this->createFailureResponse('Booking message not found');
		}

		$bookingMessage = $this->bookingMessageRepository->getByExternalId(
			$senderCode,
			$externalMessageId,
		);
		if (!$bookingMessage)
		{
			return $this->createFailureResponse('Booking message not found');
		}

		$this->bookingMessage = $bookingMessage;
		$this->setContextBookingByMessage($bookingMessage, $userId);

		if (!$this->contextBooking)
		{
			return $this->createFailureResponse('Context booking not found');
		}

		return $this->doExecuteStructured($userId, ...$args);
	}

	abstract protected function doExecuteStructured(int $userId, ...$args): array;

	protected function hasAccessToBooking(Booking $booking): bool
	{
		$bookingPrimaryClient = $booking->getPrimaryClient();
		$contextBookingPrimaryClient = $this->contextBooking->getPrimaryClient();

		if (
			!$bookingPrimaryClient
			|| !$contextBookingPrimaryClient
		)
		{
			return false;
		}

		return $bookingPrimaryClient->isEqual($contextBookingPrimaryClient);
	}

	private function setContextBookingByMessage(BookingMessage $bookingMessage, int $userId): void
	{
		$bookingId = $bookingMessage->getBookingId();
		if (!$bookingId)
		{
			return;
		}

		$booking = $this->bookingRepository->getList(
			filter: new BookingFilter([
				'ID' => $bookingId,
				'INCLUDE_DELETED' => true,
			]),
			select: (new BookingSelect(['CLIENTS']))->prepareSelect(),
			userId: $userId,
		)->getFirstCollectionItem();

		if (
			!$booking
			|| $booking->getClientCollection()->isEmpty()
		)
		{
			return;
		}

		$this->contextBooking = $booking;
	}
}
