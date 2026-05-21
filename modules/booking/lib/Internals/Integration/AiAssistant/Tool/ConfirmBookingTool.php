<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant\Tool;

use Bitrix\Booking\Command\Booking\ConfirmBookingCommand;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;

class ConfirmBookingTool extends BaseBookingTool
{
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

		if ($booking->getId() !== $this->contextBooking->getId())
		{
			return $this->createFailureResponse('Access denied');
		}

		if (!$booking->isConfirmed())
		{
			$result = (new ConfirmBookingCommand(id: $booking->getId(), updatedBy: $userId))->run();
			if (!$result->isSuccess())
			{
				return $this->createFailureResponse(implode(', ', $result->getErrorMessages()));
			}
		}

		return "Booking with identifier '{$booking->getId()}' has been successfully confirmed";
	}

	public function getName(): string
	{
		return 'confirm_booking_tool';
	}

	public function getDescription(): string
	{
		return 'Confirms the current booking (the booking associated with this conversation). The client acknowledges and accepts the booking. Only the context booking can be confirmed.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'bookingId' => [
					'type' => 'integer',
					'description' => 'Identifier of the booking to confirm. Must be a positive integer.',
				],
			],
			'required' => [
				'bookingId',
			],
		];
	}
}
