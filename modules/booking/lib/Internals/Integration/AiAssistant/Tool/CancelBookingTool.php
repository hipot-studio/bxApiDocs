<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant\Tool;

use Bitrix\Booking\Command\Booking\RemoveBookingCommand;
use Bitrix\Booking\Entity\Booking\BookingDeletionScenario;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;

class CancelBookingTool extends BaseBookingTool
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
			select: (new BookingSelect(['CLIENTS']))->prepareSelect(),
		)->getFirstCollectionItem();
		if (!$booking)
		{
			return $this->createFailureResponse('Booking has not been found');
		}

		if (!$this->hasAccessToBooking($booking))
		{
			return $this->createFailureResponse('Access denied');
		}

		$command = new RemoveBookingCommand(
			id: $booking->getId(),
			removedBy: 0,
			scenario: BookingDeletionScenario::ClientMcpTool,
		);
		$result = $command->run();
		if (!$result->isSuccess())
		{
			return $this->createFailureResponse(implode(', ', $result->getErrorMessages()));
		}

		return "Booking with identifier '{$booking->getId()}' has been successfully cancelled";
	}

	public function getName(): string
	{
		return 'cancel_booking_tool';
	}

	public function getDescription(): string
	{
		return 'Cancels a specified booking. This action is irreversible.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'bookingId' => [
					'type' => 'integer',
					'description' => 'Identifier of the booking to cancel. Must be a positive integer.',
				],
			],
			'required' => [
				'bookingId',
			],
		];
	}
}
