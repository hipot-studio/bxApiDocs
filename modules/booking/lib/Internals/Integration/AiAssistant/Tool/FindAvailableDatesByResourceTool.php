<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Internals\Service\AiAssistant\DateTimeService;
use Bitrix\Booking\Internals\Service\ResourceAvailabilityService;
use Bitrix\Booking\Internals\Service\Time;

class FindAvailableDatesByResourceTool extends BaseBookingTool
{
	private DateTimeService $dateTimeService;
	private ResourceAvailabilityService $resourceAvailabilityService;
	private ResourceRepositoryInterface $resourceRepository;

	private const MAX_SEARCH_PERIOD_IN_DAYS = 30;

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

		$dateFrom = $this->dateTimeService->createDate(
			isset($args['dateFrom']) ? (string)$args['dateFrom'] : '',
			$timezone
		);
		if (!$dateFrom)
		{
			return $this->createFailureResponse('Cannot create dateFrom');
		}

		$dateTo = $this->dateTimeService->createDate(
			isset($args['dateTo']) ? (string)$args['dateTo'] : '',
			$timezone,
			true,
		);
		if (!$dateTo)
		{
			return $this->createFailureResponse('Cannot create dateTo');
		}

		$searchDatePeriod = $this->dateTimeService->createDatePeriod($dateFrom, $dateTo);
		if (!$searchDatePeriod)
		{
			return $this->createFailureResponse('Cannot create search period');
		}
		if ($searchDatePeriod->diffMinutes() > Time::MINUTES_IN_DAY * self::MAX_SEARCH_PERIOD_IN_DAYS )
		{
			return $this->createFailureResponse(
				'The period between dateFrom and dateTo must not exceed ' . self::MAX_SEARCH_PERIOD_IN_DAYS . ' days.'
			);
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

		$dates = $this->resourceAvailabilityService->getAvailableDatesForResourceCollection(
			$searchDatePeriod,
			new ResourceCollection($resource),
			isset($args['rescheduleBookingId']) ? (int)$args['rescheduleBookingId'] : null
		);

		return $this->createSuccessResponse(
			message: 'Available dates retrieved',
			data: ['dates' => $dates],
		);
	}

	public function getName(): string
	{
		return 'find_available_dates_by_resource_tool';
	}

	public function getDescription(): string
	{
		return 'Returns calendar dates (days) that have at least one available time slot for a specified resource within a date range.'
			. ' Response shape: {"dates": ["YYYY-MM-DD", ...]}, where each item is a calendar date in strict ISO "YYYY-MM-DD" format. Examples: "2026-05-08" = May 8, 2026.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dateFrom' => [
					'type' => 'string',
					'format' => 'date',
					'description' => "Search period start date in '" . $this->dateTimeService->getDateFormat() . "' format",
				],
				'dateTo' => [
					'type' => 'string',
					'format' => 'date',
					'description' => "Search period end date in '" . $this->dateTimeService->getDateFormat() . "' format",
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
				'dateFrom',
				'dateTo',
				'resourceId',
			],
		];
	}
}
