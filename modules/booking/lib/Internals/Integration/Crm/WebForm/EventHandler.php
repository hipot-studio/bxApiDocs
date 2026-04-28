<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm\WebForm;

use Bitrix\Booking\Command\Booking\AddBookingCommand;
use Bitrix\Booking\Command\Booking\BookingResult;
use Bitrix\Booking\Internals\Service\Feature\BookingConfirmContext;
use Bitrix\Booking\Internals\Service\Feature\BookingConfirmLink;
use Bitrix\Crm;
use Bitrix\Crm\Badge\Badge;
use Bitrix\Crm\Badge\SourceIdentifier;
use Bitrix\Crm\Badge\Type\BookingStatus;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Timeline\Monitor;
use Bitrix\Crm\Timeline\Booking\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Event;
use Bitrix\Main\Result;

class EventHandler
{
	public function __construct(
		private readonly BookingBuilder $builder,
	)
	{
	}

	public function handle(Event $event): string
	{
		$value = $event->getParameter('VALUE');
		$crmEntityList = $event->getParameter('CRM_ENTITY_LIST');
		$crmEntityList = is_array($crmEntityList) ? $crmEntityList : [];

		$booking = $this->builder->build(
			is_array($value) ? $value : [],
			$crmEntityList,
			$event->getParameter('PAYMENT_ID'),
		);
		$timelineBindings = $this->builder->getTimelineBindings();

		/** @var Result|BookingResult $addResult */
		$addResult = (new AddBookingCommand(
			createdBy: (int)CurrentUser::get()->getId(),
			booking: $booking,
		))->run();

		if (!$addResult->isSuccess())
		{
			$this->handleBookingCreationError($event, $timelineBindings);

			//@todo add failure url
			return '';
		}

		return (new BookingConfirmLink())->getLink(
			$addResult->getBooking(),
			BookingConfirmContext::Info
		);
	}

	private function handleBookingCreationError(Event $event, array $timelineBindings): void
	{
		Controller::getInstance()->onBookingCreationError(
			$timelineBindings,
			[
				'entityTypeId' => $event->getParameter('CRM_ENTITY_TYPE'),
				'entityId' => $event->getParameter('CRM_ENTITY_ID'),
				'phoneNumber' => $event->getParameter('PHONE_NUMBER'),
			]
		);

		if (!empty($timelineBindings))
		{
			$badge = Crm\Service\Container::getInstance()->getBadge(
				Badge::BOOKING_STATUS_TYPE,
				BookingStatus::NOT_BOOKED_CLIENT
			);

			$sourceIdentifier = new SourceIdentifier(
				SourceIdentifier::BOOKING_BOOKING_TYPE_PROVIDER,
				0,
				0
			);

			foreach ($timelineBindings as $binding)
			{
				$itemIdentifier = new ItemIdentifier($binding['OWNER_TYPE_ID'], $binding['OWNER_ID']);

				$badge->upsert($itemIdentifier, $sourceIdentifier);

				Monitor::getInstance()->onBadgesSync($itemIdentifier);
			}
		}
	}
}
