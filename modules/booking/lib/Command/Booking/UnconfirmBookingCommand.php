<?php

declare(strict_types=1);

namespace Bitrix\Booking\Command\Booking;

use Bitrix\Booking\Command\AbstractCommand;
use Bitrix\Booking\Internals\Exception\ErrorBuilder;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Main\Result;

class UnconfirmBookingCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $id,
		public readonly int $updatedBy,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'updatedBy' => $this->updatedBy,
		];
	}

	public static function mapFromArray(array $props): self
	{
		return new self(
			id: $props['id'],
			updatedBy: $props['updatedBy'],
		);
	}

	protected function execute(): Result
	{
		try
		{
			return new BookingResult(
				(new UnconfirmBookingCommandHandler())($this),
			);
		}
		catch (Exception $bookingException)
		{
			return (new Result())->addError(ErrorBuilder::buildFromException($bookingException));
		}
	}
}
