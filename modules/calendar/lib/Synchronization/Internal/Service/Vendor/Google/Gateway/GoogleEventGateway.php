<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Internal\Service\Vendor\Google\Gateway;

use Bitrix\Calendar\Core\Base\Date;
use Bitrix\Calendar\Core\Event\Event;
use Bitrix\Calendar\Sync\Google\EventConverter;
use Bitrix\Calendar\Sync\Google\Helper;
use Bitrix\Calendar\Synchronization\Internal\Entity\EventConnection;
use Bitrix\Calendar\Synchronization\Internal\Entity\SectionConnection;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\AccessDeniedException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\BadRequestException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\ConflictException;
use Bitrix\Calendar\Synchronization\Internal\Exception\DtoValidationException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\Google\RateLimitExceededException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\Google\SyncTokenNotValidException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\NoResponseException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\NotAuthorizedException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\NotFoundException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\UnexpectedException;
use Bitrix\Calendar\Synchronization\Internal\Service\Vendor\Google\Dto\EventListResponse;
use Bitrix\Calendar\Synchronization\Internal\Service\Vendor\Google\Dto\EventResponse;
use Bitrix\Calendar\Synchronization\Internal\Service\Vendor\Google\Dto\EventSearchItemResponse;
use Bitrix\Calendar\Synchronization\Internal\Service\Vendor\Google\Dto\EventSearchPageResponse;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\HttpClient;

class GoogleEventGateway extends AbstractGoogleGateway
{
	/**
	 * @throws AccessDeniedException
	 * @throws BadRequestException
	 * @throws ConflictException
	 * @throws DtoValidationException
	 * @throws NotAuthorizedException
	 * @throws NotFoundException
	 * @throws RateLimitExceededException
	 * @throws UnexpectedException
	 * @throws NoResponseException
	 */
	public function createEvent(Event $event, string $googleCalendarId, bool $withICalUid): EventResponse
	{
		try
		{
			$data = (new EventConverter($event))->convertForCreate();
		}
		catch (SystemException $e)
		{
			throw new UnexpectedException(
				sprintf('Unable to convert event "%d": "%s"', $event->getId(), $e->getMessage()),
				$e->getCode(),
				$e
			);
		}

		if (!$withICalUid)
		{
			unset($data['iCalUID']);
		}

		$data['extendedProperties'] = $this->buildExtendedProperties($event);

		$this->request(
			HttpClient::HTTP_POST,
			self::BASE_PATH . sprintf('/calendars/%s/events/', urlencode($googleCalendarId)),
			$this->encode($data),
		);

		if ($this->isRequestSuccess())
		{
			return EventResponse::fromJson($this->client->getResult());
		}

		$this->processErrors('Event was not created');
	}

	/**
	 * @throws AccessDeniedException
	 * @throws BadRequestException
	 * @throws DtoValidationException
	 * @throws NoResponseException
	 * @throws NotAuthorizedException
	 * @throws NotFoundException
	 * @throws RateLimitExceededException
	 * @throws UnexpectedException
	 */
	public function updateEvent(Event $event, EventConnection $eventConnection, string $googleCalendarId): EventResponse
	{
		$data = $this->buildEventDataForUpdate($event, $eventConnection);

		return $this->putEvent($data, $googleCalendarId, $eventConnection->getVendorEventId());
	}

	/**
	 * Replaces an event found in the calendar with the current data of the event, keeping the event's own iCalUID,
	 * the bxId marker by which it was found and its Google attendees, as an ordinary update does.
	 *
	 * @throws AccessDeniedException
	 * @throws BadRequestException
	 * @throws DtoValidationException
	 * @throws NoResponseException
	 * @throws NotAuthorizedException
	 * @throws NotFoundException
	 * @throws RateLimitExceededException
	 * @throws UnexpectedException
	 */
	public function updateFoundEvent(
		Event $event,
		EventSearchItemResponse $foundEvent,
		string $googleCalendarId,
	): EventResponse
	{
		$eventConnection = (new EventConnection())
			->setVendorEventId($foundEvent->id)
			->setData($foundEvent->attendees ? ['attendees' => $foundEvent->attendees] : [])
		;

		$data = $this->buildEventDataForUpdate($event, $eventConnection);
		unset($data['iCalUID']);
		$data['extendedProperties'] = $this->buildExtendedProperties($event);

		return $this->putEvent($data, $googleCalendarId, $foundEvent->id);
	}

	/**
	 * Returns all events of the calendar, cancelled ones included, marked with the bxId.
	 *
	 * @return EventSearchItemResponse[]
	 *
	 * @throws AccessDeniedException
	 * @throws BadRequestException
	 * @throws DtoValidationException
	 * @throws NoResponseException
	 * @throws NotAuthorizedException
	 * @throws NotFoundException
	 * @throws RateLimitExceededException
	 * @throws UnexpectedException
	 */
	public function findEventsByBxId(string $googleCalendarId, int $bxId): array
	{
		return $this->findEvents($googleCalendarId, ['privateExtendedProperty' => 'bxId=' . $bxId]);
	}

	/**
	 * Returns all events of the calendar, cancelled ones included, with the iCalUID.
	 *
	 * @return EventSearchItemResponse[]
	 *
	 * @throws AccessDeniedException
	 * @throws BadRequestException
	 * @throws DtoValidationException
	 * @throws NoResponseException
	 * @throws NotAuthorizedException
	 * @throws NotFoundException
	 * @throws RateLimitExceededException
	 * @throws UnexpectedException
	 */
	public function findEventsByICalUid(string $googleCalendarId, string $iCalUid): array
	{
		return $this->findEvents($googleCalendarId, ['iCalUID' => $iCalUid]);
	}

	/**
	 * Filters are not compatible with a sync token, and the import window would hide events, so the search reads
	 * every page of the whole calendar on its own page tokens.
	 *
	 * @return EventSearchItemResponse[]
	 *
	 * @throws AccessDeniedException
	 * @throws BadRequestException
	 * @throws DtoValidationException
	 * @throws NoResponseException
	 * @throws NotAuthorizedException
	 * @throws NotFoundException
	 * @throws RateLimitExceededException
	 * @throws UnexpectedException
	 */
	private function findEvents(string $googleCalendarId, array $filter): array
	{
		$items = [];
		$pageToken = null;
		$readPageTokens = [];

		do
		{
			$params = $filter;
			$params['showDeleted'] = 'true';

			if ($pageToken !== null)
			{
				$params['pageToken'] = $pageToken;
				$readPageTokens[$pageToken] = true;
			}

			$this->request(
				HttpClient::HTTP_GET,
				sprintf(
					'%s/calendars/%s/events?%s',
					self::BASE_PATH,
					urlencode($googleCalendarId),
					http_build_query($params, '', '&', PHP_QUERY_RFC3986),
				),
			);

			if (!$this->isRequestSuccess())
			{
				$this->processErrors('Events search was not retrieved from Google');
			}

			$page = EventSearchPageResponse::fromJson($this->client->getResult());

			array_push($items, ...$page->items);

			$pageToken = $page->nextPageToken;
		}
		while ($pageToken !== null && !isset($readPageTokens[$pageToken]));

		return $items;
	}

	/**
	 * @throws AccessDeniedException
	 * @throws BadRequestException
	 * @throws DtoValidationException
	 * @throws NoResponseException
	 * @throws NotAuthorizedException
	 * @throws NotFoundException
	 * @throws RateLimitExceededException
	 * @throws UnexpectedException
	 */
	private function putEvent(array $data, string $googleCalendarId, string $vendorEventId): EventResponse
	{
		$this->request(
			HttpClient::HTTP_PUT,
			sprintf(
				'%s/calendars/%s/events/%s',
				self::BASE_PATH,
				urlencode($googleCalendarId),
				urlencode($vendorEventId),
			),
			$this->encode($data),
		);

		if ($this->isRequestSuccess())
		{
			return EventResponse::fromJson($this->client->getResult());
		}

		$this->processErrors('Event was not updated');
	}

	private function buildExtendedProperties(Event $event): array
	{
		return [
			'private' => [
				'bxId' => $event->getId(),
			],
		];
	}

	/**
	 * @throws AccessDeniedException
	 * @throws BadRequestException
	 * @throws NotAuthorizedException
	 * @throws RateLimitExceededException
	 * @throws UnexpectedException
	 * @throws NoResponseException
	 */
	public function deleteEvent(string $googleCalendarId, string $googleEventId): void
	{
		$this->request(
			HttpClient::HTTP_DELETE,
			sprintf(
				'%s/calendars/%s/events/%s',
				self::BASE_PATH,
				urlencode($googleCalendarId),
				urlencode($googleEventId),
			),
			$this->encode(
				[
					'sendUpdates' => 'all',
				],
			),
		);

		if ($this->isDeleteRequestSuccess())
		{
			return;
		}

		$this->processErrors('Event was not deleted');
	}

	/**
	 * @throws AccessDeniedException
	 * @throws BadRequestException
	 * @throws DtoValidationException
	 * @throws NotAuthorizedException
	 * @throws NotFoundException
	 * @throws UnexpectedException
	 * @throws RateLimitExceededException
	 */
	public function createInstance(Event $event, string $masterVendorEventId, string $googleCalendarId): EventResponse
	{
		$eventConnection = new EventConnection();

		$eventConnection
			->setVendorEventId($event->buildInstanceId($masterVendorEventId))
			->setRecurrenceId($masterVendorEventId)
		;

		return $this->updateEvent($event, $eventConnection, $googleCalendarId);
	}

	/**
	 * @throws AccessDeniedException
	 * @throws BadRequestException
	 * @throws DtoValidationException
	 * @throws NotAuthorizedException
	 * @throws NotFoundException
	 * @throws RateLimitExceededException
	 * @throws UnexpectedException
	 * @throws NoResponseException
	 */
	public function deleteInstance(
		Event $masterEvent,
		string $masterVendorEventId,
		string $googleCalendarId,
		Date $excludeDate,
		?Date $originalDate = null,
	): EventResponse
	{
		$instance = $this->getInstanceForDay($masterEvent, $excludeDate, $originalDate);

		$eventConnection = new EventConnection();

		$eventConnection
			->setVendorEventId($instance->buildInstanceId($masterVendorEventId))
			->setRecurrenceId($masterVendorEventId)
		;

		$data = $this->buildEventDataForDelete($instance, $eventConnection);

		$this->request(
			HttpClient::HTTP_PUT,
			sprintf(
				'%s/calendars/%s/events/%s',
				self::BASE_PATH,
				urlencode($googleCalendarId),
				urlencode($eventConnection->getVendorEventId()),
			),
			$this->encode($data),
		);

		if ($this->isRequestSuccess())
		{
			return EventResponse::fromJson($this->client->getResult());
		}

		$this->processErrors('Event instance was not deleted');
	}

	/**
	 * @throws UnexpectedException
	 */
	private function buildEventDataForUpdate(Event $event, EventConnection $eventConnection): array
	{
		try
		{
			$data = (new EventConverter($event))->convertForCreate();
		}
		catch (SystemException $e)
		{
			throw new UnexpectedException(
				sprintf('Unable to convert event "%d": "%s"', $event->getId(), $e->getMessage()),
				$e->getCode(),
				$e
			);
		}

		$data['id'] = $eventConnection->getVendorEventId();

		if ($event->isInstance())
		{
			$data['recurringEventId'] = $eventConnection->getRecurrenceId();
		}

		$eventData = $eventConnection->getData();

		if (!empty($eventData['attendees']))
		{
			$data['attendees'] = $eventData['attendees'];
		}

		return $data;
	}

	/**
	 * @throws UnexpectedException
	 */
	private function buildEventDataForDelete(Event $event, EventConnection $eventConnection): array
	{
		$data = $this->buildEventDataForUpdate($event, $eventConnection);

		$data['status'] = 'cancelled';

		return $data;
	}

	private function getInstanceForDay(Event $event, Date $excludedDate, ?Date $originalDate = null): Event
	{
		$instanceEvent = clone $event;

		$instanceStart = $this->cloneWithReplacedDate($event->getStart(), $excludedDate);
		$instanceEvent->setStart($instanceStart);

		$instanceEnd = $this->cloneWithReplacedDate($event->getEnd(), $excludedDate);
		$instanceEvent->setEnd($this->cloneWithReplacedDate($instanceEnd, $excludedDate));

		$originalDateFrom = $originalDate ?? $instanceEvent->getStart();
		$instanceEvent
			->setOriginalDateFrom(clone $originalDateFrom)
			->setRecurringRule(null)
		;

		return $instanceEvent;
	}

	private function cloneWithReplacedDate(Date $date, Date $replacedDate): Date
	{
		$date = clone $date;

		$date->getDate()->setDate(
			$replacedDate->getYear(),
			$replacedDate->getMonth(),
			$replacedDate->getDay(),
		);

		return $date;
	}

	/**
	 * @throws DtoValidationException
	 * @throws NotAuthorizedException
	 * @throws NotFoundException
	 * @throws RateLimitExceededException
	 * @throws SyncTokenNotValidException
	 * @throws UnexpectedException
	 * @throws NoResponseException
	 */
	public function getEvents(SectionConnection $sectionConnection): EventListResponse
	{
		$this->request(
			HttpClient::HTTP_GET,
			$this->prepareEventsListUrl($sectionConnection),
		);

		if ($this->isRequestSuccess())
		{
			$response = EventListResponse::fromJson($this->client->getResult());

			// Defense-in-depth: during an initial (full) sync Google paginates the whole calendar, so
			// ANY paginated request returns only a slice and must not drive deprecated-instance pruning -
			// otherwise instances delivered on an earlier page look "removed" once a later page omits them
			// (an initial sync IS NOT empty after its first page: earlier pages already persisted events).
			// A page is a slice when either more pages follow (nextPageToken present) OR the request itself
			// carried a pageToken (a continuation page - including the final one, whose nextPageToken is
			// already empty). Only a single-page initial sync (no pageToken in, no nextPageToken out) is a
			// complete snapshot and may prune. Explicit vendor tombstones stay authoritative - they are not
			// gated by isPartial; only the "missing instance" inference is suppressed and reconciled on the
			// next full pass. Incremental syncs (syncToken present) deliver each change once per page, so
			// they are left untouched to keep normal pagination and deletion delivery intact.
			if (
				empty($sectionConnection->getSyncToken())
				&& (!empty($sectionConnection->getPageToken()) || !empty($response->nextPageToken))
			)
			{
				$response->isPartial = true;
			}

			return $response;
		}

		$this->processErrors('Events list was not retrieved from Google');
	}

	private function prepareEventsListUrl(SectionConnection $sectionConnection): string
	{
		$url = sprintf('%s/calendars/%s/events', self::BASE_PATH, urlencode($sectionConnection->getVendorSectionId()));
		$url .= '?' . preg_replace('/(%3D)/', '=', http_build_query($this->buildListRequestParams($sectionConnection)));

		return $url;
	}

	private function buildListRequestParams(SectionConnection $sectionConnection): array
	{
		$params = [
			'pageToken' => $sectionConnection->getPageToken(),
			'showDeleted' => 'true',
		];

		if ($syncToken = $sectionConnection->getSyncToken())
		{
			$params['syncToken'] = $syncToken;
		}
		else
		{
			$params['maxResults'] = 50;
			$params['timeMin'] = (new Date())->sub('1 months')->format(Helper::DATE_TIME_FORMAT_WITH_MICROSECONDS);
		}

		return $params;
	}
}
