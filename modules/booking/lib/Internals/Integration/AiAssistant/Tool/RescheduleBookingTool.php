<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Booking\Command\Booking\UpdateBookingCommand;
use Bitrix\Booking\Entity\DatePeriod;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\AiAssistant\DateTimeService;
use Bitrix\Booking\Internals\Service\ResourceAvailabilityService;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;
use DateInterval;

class RescheduleBookingTool extends BaseBookingTool
{
	private DateTimeService $dateTimeService;
	private ResourceAvailabilityService $resourceAvailabilityService;

	public function __construct(TracedLogger $tracedLogger)
	{
		parent::__construct($tracedLogger);

		$this->dateTimeService = Container::getAiAssistantDateTimeService();
		$this->resourceAvailabilityService = Container::getAiAssistantResourceAvailabilityService();
	}

	protected function execute(int $userId, ...$args): string
	{
		$bookingId = (int)($args['bookingId'] ?? 0);
		if (!$bookingId)
		{
			return $this->createFailureResponse('Booking has not been found');
		}

		$booking = $this->bookingRepository->getList(
			filter: new BookingFilter([
				'ID' => $bookingId,
			]),
			select: (new BookingSelect([
				'CLIENTS',
				'RESOURCES',
			]))->prepareSelect(),
		)->getFirstCollectionItem();

		if (!$booking)
		{
			return $this->createFailureResponse('Booking has not been found');
		}

		if (!$this->hasAccessToBooking($booking))
		{
			return $this->createFailureResponse('Access denied');
		}

		$dateFrom = $this->dateTimeService->createDateTime(
			isset($args['dateTime']) ? (string)$args['dateTime'] : '',
			$this->contextBooking->getDatePeriod()?->getDateFrom()->getTimezone()?->getName() ?? '',
		);
		if (!$dateFrom)
		{
			return $this->createFailureResponse('Cannot create dateTime');
		}

		if ($dateFrom->getTimestamp() < time())
		{
			return $this->createFailureResponse('Cannot reschedule booking to a time in the past');
		}

		$newDatePeriod = new DatePeriod(
			dateFrom: $dateFrom,
			dateTo: $dateFrom->add(new DateInterval('PT' . $booking->getDatePeriod()->diffMinutes() . 'M')),
		);

		$isRescheduleAvailable = $this->resourceAvailabilityService->isRescheduleAvailableForBookingResources(
			$booking->getResourceCollection(),
			$newDatePeriod,
			$booking->getId(),
		);
		if (!$isRescheduleAvailable)
		{
			return $this->createFailureResponse('Cannot reschedule booking to the specified time');
		}

		$command = new UpdateBookingCommand(
			updatedBy: 0,
			booking: $booking->setDatePeriod($newDatePeriod),
		);
		$result = $command->run();
		if (!$result->isSuccess())
		{
			return $this->createFailureResponse(implode(', ', $result->getErrorMessages()));
		}

		return "Booking with identifier '{$booking->getId()}' has been successfully rescheduled";
	}

	public function getName(): string
	{
		return 'reschedule_booking_tool';
	}

	public function getDescription(): string
	{
		return 'Reschedules an existing booking to a new date and time. The booking duration and assigned resource remain unchanged. The new time must be in the future and the resource must be available at that time. Use find_available_slots_* with rescheduleBookingId to check availability before calling this tool.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'bookingId' => [
					'type' => 'integer',
					'description' => 'Identifier of the booking to reschedule. Must be a positive integer.',
				],
				'dateTime' => [
					'type' => 'string',
					'format' => 'date-time',
					'description' => "New booking time in '" . $this->dateTimeService->getDateTimeFormat() . "' format",
				],
			],
			'required' => [
				'bookingId',
				'dateTime',
			],
		];
	}
}
