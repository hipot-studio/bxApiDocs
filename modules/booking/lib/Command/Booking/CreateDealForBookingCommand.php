<?php

declare(strict_types=1);

namespace Bitrix\Booking\Command\Booking;

use Bitrix\Booking\Command\AbstractCommand;
use Bitrix\Booking\Internals\Exception\ErrorBuilder;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Main\Result;

class CreateDealForBookingCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $bookingId,
		public readonly int $updatedBy,
	)
	{
	}

	protected function execute(): Result
	{
		try
		{
			return new BookingResult(
				booking: (new CreateDealForBookingCommandHandler())($this),
			);
		}
		catch (Exception $bookingException)
		{
			return (new Result())->addError(ErrorBuilder::buildFromException($bookingException));
		}
	}

	public function toArray(): array
	{
		return [
			'bookingId' => $this->bookingId,
			'updatedBy' => $this->updatedBy,
		];
	}
}
