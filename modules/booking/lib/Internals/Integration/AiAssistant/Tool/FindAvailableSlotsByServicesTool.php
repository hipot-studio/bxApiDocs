<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\AiAssistant\DateTimeService;
use Bitrix\Booking\Internals\Service\AiAssistant\ResourceSkuService;
use Bitrix\Booking\Internals\Service\ResourceAvailabilityService;

class FindAvailableSlotsByServicesTool extends BaseBookingTool
{
	private DateTimeService $dateTimeService;
	private ResourceSkuService $resourceSkuService;
	private ResourceAvailabilityService $resourceAvailabilityService;

	public function __construct(TracedLogger $tracedLogger)
	{
		parent::__construct($tracedLogger);

		$this->dateTimeService = Container::getAiAssistantDateTimeService();
		$this->resourceSkuService = Container::getAiAssistantResourceSkuService();
		$this->resourceAvailabilityService = Container::getAiAssistantResourceAvailabilityService();
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

		$skuCollection = $this->resourceSkuService->createSkuCollection(
			isset($args['serviceIds'])
				? (array)$args['serviceIds']
				: []
		);
		if ($skuCollection->isEmpty())
		{
			return $this->createFailureResponse('Specified services have not been found');
		}

		$resourceCollection = $this->resourceSkuService->getResourceCollectionBySkuCollection($skuCollection);
		if ($resourceCollection->isEmpty())
		{
			return $this->createFailureResponse('Resources providing all specified service(s) have not been found');
		}

		$slots = $this->resourceAvailabilityService->getAvailableSlotsForResourceCollection(
			$resourceCollection,
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
		return 'find_available_slots_by_services_tool';
	}

	public function getDescription(): string
	{
		return 'Returns specific bookable time windows for the specified services on a given date.'
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
				'serviceIds' => [
					'type' => 'array',
					'items' => [
						'type' => 'integer',
					],
					'minItems' => 1,
					'description' => 'List of service identifiers',
				],
				'rescheduleBookingId' => [
					'type' => ['integer', 'null'],
					'description' => 'Optional. ID of an existing booking being rescheduled. When provided, the time slot of this booking is treated as available so it appears in search results.',
				],
			],
			'required' => [
				'date',
				'serviceIds',
			],
		];
	}
}
