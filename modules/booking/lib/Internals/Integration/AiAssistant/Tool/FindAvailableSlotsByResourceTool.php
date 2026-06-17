<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Internals\Service\AiAssistant\DateTimeService;
use Bitrix\Booking\Internals\Service\ResourceAvailabilityService;

class FindAvailableSlotsByResourceTool extends BaseBookingTool
{
	private DateTimeService $dateTimeService;
	private ResourceAvailabilityService $resourceAvailabilityService;
	private ResourceRepositoryInterface $resourceRepository;

	public function __construct(TracedLogger $tracedLogger)
	{
		parent::__construct($tracedLogger);

		$this->dateTimeService = Container::getAiAssistantDateTimeService();
		$this->resourceAvailabilityService = Container::getAiAssistantResourceAvailabilityService();
		$this->resourceRepository = Container::getResourceRepository();
	}

	protected function doExecuteStructured(int $userId, ...$args): array
	{
		$timezone = $this->contextBooking->getDatePeriod()?->getDateFrom()->getTimezone()?->getName() ?? '';

		$date = $this->dateTimeService->createDate(
			isset($args['date']) ? (string)$args['date'] : '',
			$timezone
		);
		if (!$date)
		{
			return $this->createFailureResponse('Cannot create date');
		}

		$resourceId = (int)($args['resourceId'] ?? 0);
		if (!$resourceId)
		{
			return $this->createFailureResponse('Resource not found');
		}

		$resource = $this->resourceRepository->getById($resourceId);
		if (!$resource)
		{
			return $this->createFailureResponse('Resource not found');
		}

		$slots = $this->resourceAvailabilityService->getAvailableSlotsForResourceCollection(
			new ResourceCollection($resource),
			$date,
			isset($args['rescheduleBookingId']) ? (int)$args['rescheduleBookingId'] : null
		);

		return $this->createSuccessResponse(
			message: 'Available slots retrieved',
			data: ['slots' => $slots],
		);
	}

	public function getName(): string
	{
		return 'find_available_slots_by_resource_tool';
	}

	public function getDescription(): string
	{
		return 'Returns specific bookable time windows for a specified resource on a given date.'
			. ' Response shape: {"slots": ["HH:MM", ...]}, where each item is a 24-hour clock start time in strict "HH:MM" format (hours:minutes). Examples: "09:00" = 9 hours 0 minutes, "14:30" = 14 hours 30 minutes, "20:30" = 20 hours 30 minutes. The colon separates hours and minutes — never minutes and seconds.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'date' => [
					'type' => 'string',
					'format' => 'date',
					'description' => "Date in '" . $this->dateTimeService->getDateFormat() . "' format",
				],
				'resourceId' => [
					'type' => 'integer',
					'description' => 'Identifier of the resource. Must be a positive integer.',
				],
				'rescheduleBookingId' => [
					'type' => ['integer', 'null'],
					'description' => 'Optional. ID of an existing booking being rescheduled. When provided, the time slot of this booking is treated as available so it appears in search results.',
				],
			],
			'required' => [
				'date',
				'resourceId',
			],
		];
	}
}
