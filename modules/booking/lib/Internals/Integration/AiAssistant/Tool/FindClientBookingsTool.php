<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Booking\Entity\Booking\BookingCollection;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\AiAssistant\Mapper\BookingMapper;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;
use Bitrix\Booking\Provider\Params\Booking\BookingSort;

class FindClientBookingsTool extends BaseBookingTool
{
	private BookingMapper $bookingMapper;

	public function __construct(TracedLogger $tracedLogger)
	{
		parent::__construct($tracedLogger);

		$this->bookingMapper = Container::getAiAssistantBookingMapper();
	}

	protected function doExecuteStructured(int $userId, ...$args): array
	{
		$bookings = $this->formatBookingCollection($this->getClientBookingCollection());

		return $this->createSuccessResponse(
			message: 'Client bookings retrieved',
			data: ['bookings' => $bookings],
		);
	}

	public function getName(): string
	{
		return 'find_client_bookings_tool';
	}

	public function getDescription(): string
	{
		return 'Returns up to 10 upcoming (future) bookings for the current client, sorted by date ascending. Each booking includes: id, clientName, dateTime, resource (id, name, typeName), and services (each with id, name, price, currencyId). Use this to look up booking IDs needed for reschedule, or cancel operations.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => new \stdClass(),
		];
	}

	private function getClientBookingCollection(): BookingCollection
	{
		$clientTypeModule = $this->contextBooking->getPrimaryClient()->getType()?->getModuleId();
		$clientTypeCode = $this->contextBooking->getPrimaryClient()->getType()?->getCode() ?? null;
		$clientId = $this->contextBooking->getPrimaryClient()->getId() ?? null;
		if (
			!$clientTypeModule
			|| !$clientTypeCode
			|| !$clientId
		)
		{
			return new BookingCollection();
		}

		$bookingCollection = $this->bookingRepository->getList(
			limit: 10,
			filter: new BookingFilter([
				'WITHIN' => [
					'DATE_FROM' => time(),
				],
				mb_strtoupper($clientTypeModule) . '_' . $clientTypeCode . '_ID' => [$clientId],
			]),
			sort: (new BookingSort([
				'DATE_FROM' => 'ASC',
			]))->prepareSort(),
			select: (new BookingSelect([
				'CLIENTS',
				'RESOURCES',
				'SKUS',
			]))->prepareSelect(),
		);

		$result = new BookingCollection();
		foreach ($bookingCollection as $booking)
		{
			if (!$this->hasAccessToBooking($booking))
			{
				continue;
			}

			$result->add($booking);
		}

		$this->bookingRepository->withClientData($result);
		$this->bookingRepository->withSkus($result);

		return $result;
	}

	private function formatBookingCollection(BookingCollection $bookingCollection): array
	{
		$result = [];

		foreach ($bookingCollection as $booking)
		{
			$result[] = $this->bookingMapper->mapFromEntity($booking);
		}

		return $result;
	}
}
