<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\AiAssistant\DateTimeService;
use Bitrix\Booking\Internals\Service\AiAssistant\ResourceSkuService;
use Bitrix\Booking\Internals\Service\ResourceAvailabilityService;
use Bitrix\Main\Web\Json;

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

	protected function execute(int $userId, ...$args): string
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

		return Json::encode(
			$this->resourceAvailabilityService->getAvailableSlotsForResourceCollection(
				$resourceCollection,
				$date,
				isset($args['rescheduleBookingId']) ? (int)$args['rescheduleBookingId'] : null
			)
		);
	}

	public function getName(): string
	{
		return 'find_available_slots_by_services_tool';
	}

	public function getDescription(): string
	{
		return 'Returns specific bookable time windows (e.g. 10:00–10:30) for the specified services on a given date. Use when the client has chosen services but has no preference for a specific resource. Call this after using find_available_dates_by_services_tool to identify a suitable day.';
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
					'type' => 'integer',
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
