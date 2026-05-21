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
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Internals\Service\AiAssistant\DateTimeService;
use Bitrix\Booking\Internals\Service\AiAssistant\ResourceSkuService;
use Bitrix\Booking\Internals\Service\ResourceAvailabilityService;

class CreateBookingByResourceAndServicesTool extends BaseBookingTool
{
	private DateTimeService $dateTimeService;
	private ResourceAvailabilityService $resourceAvailabilityService;
	private ResourceSkuService $resourceSkuService;
	private ResourceRepositoryInterface $resourceRepository;

	public function __construct(TracedLogger $tracedLogger)
	{
		parent::__construct($tracedLogger);

		$this->dateTimeService = Container::getAiAssistantDateTimeService();
		$this->resourceAvailabilityService = Container::getAiAssistantResourceAvailabilityService();
		$this->resourceSkuService = Container::getAiAssistantResourceSkuService();
		$this->resourceRepository = Container::getResourceRepository();
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

		$skuCollection = $this->resourceSkuService->createSkuCollection(
			isset($args['serviceIds'])
				? (array)$args['serviceIds']
				: []
		);
		if ($skuCollection->isEmpty())
		{
			return $this->createFailureResponse('Specified services have not been found');
		}

		foreach ($skuCollection as $skuItem)
		{
			if (!$resource->getSkuCollection()->getByEntityId($skuItem->getId()))
			{
				return $this->createFailureResponse(
					'Resource does not provide the specified service: ' . $skuItem->getId()
				);
			}
		}

		$datePeriod = $this->resourceAvailabilityService->getAvailableDatePeriodForResource($dateFrom, $resource);
		if (!$datePeriod)
		{
			return $this->createFailureResponse('Cannot find available slot');
		}

		$command = new AddBookingCommand(
			createdBy: 0,
			booking: (new Booking())
				->setDatePeriod($datePeriod)
				->setClientCollection(
					new ClientCollection($this->contextBooking->getPrimaryClient())
				)
				->setResourceCollection(new ResourceCollection($resource))
				->setSkuCollection($skuCollection)
				->setSource(BookingSource::McpTools),
		);
		/** @var BookingResult $result */
		$result = $command->run();
		if (!$result->isSuccess())
		{
			return $this->createFailureResponse(implode(', ', $result->getErrorMessages()));
		}

		return "Booking with identifier '{$result->getBooking()->getId()}' has been successfully created";
	}

	public function getName(): string
	{
		return 'create_booking_by_resource_and_services_tool';
	}

	public function getDescription(): string
	{
		return 'Creates a new booking with a specified resource for specified services. Use when the client has chosen both a specific resource and specific services. Validates that the resource provides all specified services. The dateTime must correspond to an available slot previously retrieved via find_available_slots_by_resource_tool. The booking cannot be created in the past.';
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
				'resourceId' => [
					'type' => 'integer',
					'description' => 'Identifier of the resource. Must be a positive integer.',
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
				'resourceId',
				'serviceIds',
			],
		];
	}
}
