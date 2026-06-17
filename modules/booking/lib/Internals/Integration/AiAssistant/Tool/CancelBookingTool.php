<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant\Tool;

use Bitrix\Booking\Command\Booking\RemoveBookingCommand;
use Bitrix\Booking\Entity\Booking\BookingDeletionScenario;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;

class CancelBookingTool extends BaseBookingTool
{
	protected function doExecuteStructured(int $userId, ...$args): array
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
			select: (new BookingSelect(['CLIENTS', 'EXTERNAL_DATA']))->prepareSelect(),
		)->getFirstCollectionItem();
		if (!$booking)
		{
			return $this->createFailureResponse('Booking has not been found');
		}

		if (!$this->hasAccessToBooking($booking))
		{
			return $this->createFailureResponse('Access denied');
		}

		$crmBindings = $this->crmBindingsBuilder->getExternalDataBindingsFromBooking($booking);

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

		return $this->createSuccessResponse(
			message: "Booking with identifier '{$booking->getId()}' has been successfully cancelled",
			crmBindings: $crmBindings,
		);
	}

	public function getName(): string
	{
		return 'cancel_booking_tool';
	}

	public function getDescription(): string
	{
		return 'Call this tool as soon as the client expresses intent to cancel the booking — both explicit ("cancel it", "I won\'t come, cancel") and implicit refusals to attend ("I\'m not coming anymore", "I no longer need it"). Treat any clear refusal-to-attend as a cancellation request, regardless of phrasing or language.';
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
