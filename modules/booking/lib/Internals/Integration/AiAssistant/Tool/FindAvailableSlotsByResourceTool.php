<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Internals\Service\AiAssistant\DateTimeService;
use Bitrix\Booking\Internals\Service\ResourceAvailabilityService;
use Bitrix\Main\Web\Json;

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

		return Json::encode(
			$this->resourceAvailabilityService->getAvailableSlotsForResourceCollection(
				new ResourceCollection($resource),
				$date,
				isset($args['rescheduleBookingId']) ? (int)$args['rescheduleBookingId'] : null
			)
		);
	}

	public function getName(): string
	{
		return 'find_available_slots_by_resource_tool';
	}

	public function getDescription(): string
	{
		return 'Returns specific bookable time windows (e.g. 10:00–10:30) for a specified resource on a given date. Use when the client has chosen a specific resource. Call this after using find_available_dates_by_resource_tool to identify a suitable day.';
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
					'type' => 'integer',
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
