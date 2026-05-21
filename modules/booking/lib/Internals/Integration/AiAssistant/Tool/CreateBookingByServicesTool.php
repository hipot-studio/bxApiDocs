<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Booking\Command\Booking\AddBookingCommand;
use Bitrix\Booking\Command\Booking\BookingResult;
use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\Booking\BookingSource;
use Bitrix\Booking\Entity\Client\ClientCollection;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\AiAssistant\DateTimeService;
use Bitrix\Booking\Internals\Service\AiAssistant\ResourceSkuService;
use Bitrix\Booking\Internals\Service\ResourceAvailabilityService;

class CreateBookingByServicesTool extends BaseBookingTool
{
	private DateTimeService $dateTimeService;
	private ResourceAvailabilityService $resourceAvailabilityService;
	private ResourceSkuService $resourceSkuService;

	public function __construct(TracedLogger $tracedLogger)
	{
		parent::__construct($tracedLogger);

		$this->dateTimeService = Container::getAiAssistantDateTimeService();
		$this->resourceAvailabilityService = Container::getAiAssistantResourceAvailabilityService();
		$this->resourceSkuService = Container::getAiAssistantResourceSkuService();
	}

	protected function execute(int $userId, ...$args): string
	{
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
			return $this->createFailureResponse('Cannot create booking to a time in the past');
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

		[$foundDatePeriod, $foundResource] = $this->resourceAvailabilityService->getAvailableDatePeriodForResourceCollection(
			$dateFrom,
			$resourceCollection
		);
		if (!$foundDatePeriod || !$foundResource)
		{
			return $this->createFailureResponse('Cannot find available slot');
		}

		$command = new AddBookingCommand(
			createdBy: 0,
			booking: (new Booking())
				->setDatePeriod($foundDatePeriod)
				->setClientCollection(
					new ClientCollection($this->contextBooking->getPrimaryClient())
				)
				->setResourceCollection(new ResourceCollection($foundResource))
				->setSkuCollection($skuCollection)
				->setSource(BookingSource::McpTools),
		);
		/** @var BookingResult $result */
		$result = $command->run();
		if (!$result->isSuccess())
		{
			return $this->createFailureResponse(implode(', ', $result->getErrorMessages()));
		}
		
		return "Booking with identifier '{$result->getBooking()->getId()}' has been successfully created. Resource identifier '{$foundResource->getId()}'";
	}

	public function getName(): string
	{
		return 'create_booking_by_services_tool';
	}

	public function getDescription(): string
	{
		return 'Creates a new booking for the specified services. Use when the client has chosen services but has no preference for a specific resource. The system automatically selects an available resource that provides all requested services. The dateTime must correspond to an available slot previously retrieved via find_available_slots_by_services_tool. The booking cannot be created in the past. Returns the booking ID and the assigned resource ID.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dateTime' => [
					'type' => 'string',
					'format' => 'date-time',
					'description' => "Booking time in '" . $this->dateTimeService->getDateTimeFormat() . "' format",
				],
				'serviceIds' => [
					'type' => 'array',
					'items' => [
						'type' => 'integer',
					],
					'minItems' => 1,
					'description' => 'List of service identifiers',
				],
			],
			'required' => [
				'dateTime',
				'serviceIds',
			],
		];
	}
}
