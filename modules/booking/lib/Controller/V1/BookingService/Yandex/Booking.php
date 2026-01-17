<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1\BookingService\Yandex;

use Bitrix\Booking\Controller\V1\BookingService\Yandex\Request\Booking\CreateBookingRequest;
use Bitrix\Booking\Controller\V1\BookingService\Yandex\Response\Booking\AddBookingResponse;
use Bitrix\Booking\Controller\V1\BookingService\Yandex\Response\Booking\CancelBookingResponse;
use Bitrix\Booking\Controller\V1\BookingService\Yandex\Response\Booking\GetBookingResponse;
use Bitrix\Booking\Controller\V1\BookingService\Yandex\Response\Booking\UpdateBookingResponse;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\Yandex\BookingProvider;
use Bitrix\Booking\Internals\Service\Yandex\DeleteBookingService;
use Bitrix\Booking\Internals\Service\Yandex\CreateBookingService;
use Bitrix\Booking\Internals\Service\Yandex\UpdateBookingService;
use Bitrix\Main\Request;

class Booking extends BaseController
{
	private CreateBookingService $createBookingService;
	private UpdateBookingService $updateBookingService;
	private DeleteBookingService $deleteBookingService;
	private BookingProvider $bookingProvider;

	public function __construct(Request $request = null)
	{
		parent::__construct($request);

		$this->createBookingService = Container::getYandexCreateBookingService();
		$this->updateBookingService = Container::getYandexUpdateBookingService();
		$this->deleteBookingService = Container::getYandexDeleteBookingService();
		$this->bookingProvider = Container::getYandexBookingProvider();
	}

	public function getAction(string $bookingId): GetBookingResponse|null
	{
		return $this->handle(
			fn() => new GetBookingResponse(
				booking: $this->bookingProvider->getById($bookingId),
			)
		);
	}

	public function createAction(array $booking): AddBookingResponse|null
	{
		return $this->handle(
			fn() => new AddBookingResponse(
				booking: $this->createBookingService->create(
					CreateBookingRequest::mapFromArray(
						$booking
					)
				)
			)
		);
	}

	public function updateAction(
		string $bookingId,
		string $datetime,
		string|null $comment = null,
	): UpdateBookingResponse|null
	{
		return $this->handle(
			fn() => new UpdateBookingResponse(
				booking: $this->updateBookingService->update(
					$bookingId,
					$datetime,
					$comment,
				)
			)
		);
	}

	public function deleteAction(string $bookingId): CancelBookingResponse|null
	{
		return $this->handle(
			fn: function() use ($bookingId)
			{
				$this->deleteBookingService->delete($bookingId);

				return new CancelBookingResponse();
			}
		);
	}
}
