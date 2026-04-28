<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\Booking\BookingCollection;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Exception\Booking\CreateBookingException;
use Bitrix\Booking\Internals\Exception\Booking\RemoveBookingException;
use Bitrix\Booking\Internals\Model\BookingTable;
use Bitrix\Booking\Internals\Model\Enum\EntityType;
use Bitrix\Booking\Internals\Model\Enum\NoteType;
use Bitrix\Booking\Entity;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ORM\Mapper\BookingMapper;
use Bitrix\Booking\Internals\Repository\ORM\Trait\NoteTrait;
use Bitrix\Booking\Internals\Service\BookingSkuService;
use Bitrix\Booking\Provider\BookingProvider;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;
use Bitrix\Booking\Provider\Params\Booking\BookingSort;
use Bitrix\Booking\Provider\Params\FilterInterface;
use Bitrix\Booking\Provider\Params\GridParams;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\ORM\Query\QueryHelper;

class BookingRepository implements BookingRepositoryInterface
{
	use NoteTrait;

	public function __construct(
		private readonly BookingMapper $mapper,
		private readonly BookingSkuService $bookingSkuService,
	)
	{
	}

	public function getQuery(FilterInterface|null $filter = null): Query
	{
		$query = BookingTable::query();

		if ($filter !== null)
		{
			$filter->prepareQuery($query);
			$query->where($filter->prepareFilter());
		}

		return $query;
	}

	public function getList(
		int|null $limit = null,
		int|null $offset = null,
		FilterInterface|null $filter = null,
		array|null $sort = null,
		array|null $select = null,
		int|null $userId = null,
	): Entity\Booking\BookingCollection
	{
		$query = BookingTable::query()
			->setSelect(array_merge(['*'], $select ?: []))
		;

		if ($limit !== null)
		{
			$query->setLimit($limit);
		}

		if ($offset !== null)
		{
			$query->setOffset($offset);
		}

		if ($filter !== null)
		{
			$filter->prepareQuery($query);
			$query->where($filter->prepareFilter());
		}

		if ($sort !== null)
		{
			$query->setOrder($sort);
		}

		$ormBookings = QueryHelper::decompose($query);

		$bookings = [];
		foreach ($ormBookings as $ormBooking)
		{
			$bookings[] = $this->mapper->convertFromOrm($ormBooking);
		}

		return new Entity\Booking\BookingCollection(...$bookings);
	}

	public function getIntersectionsList(
		Entity\Booking\Booking $booking,
		int|null $userId = null,
		int|null $limit = null
	): Entity\Booking\BookingCollection
	{
		$filter = [
			'WITHIN' => [
				'DATE_FROM' => $booking->getDatePeriod()?->getDateFrom()?->getTimestamp(),
				'DATE_TO' => $booking->getMaxDate()?->getTimestamp(),
			],
			'RESOURCE_ID' => $booking->getResourceCollection()->getEntityIds(),
		];
		if ($booking->getId())
		{
			$filter['!ID'] = $booking->getId();
		}

		$result = new Entity\Booking\BookingCollection();

		$intersectingBookingCollection = $this->getList(
			limit: $limit,
			filter: new BookingFilter($filter),
			sort: (new BookingSort([
				'DATE_FROM' => 'ASC',
				'IS_RECURRING' => 'ASC',
			]))->prepareSort(),
			select: (new BookingSelect(['RESOURCES']))->prepareSelect(),
			userId: $userId,
		);
		foreach ($intersectingBookingCollection as $intersectingBooking)
		{
			$intersect = $intersectingBooking->doEventsIntersect($booking);
			if (!$intersect)
			{
				continue;
			}

			$result->add($intersectingBooking);
			if ($limit !== null && $result->count() >= $limit)
			{
				break;
			}
		}

		return $result;
	}

	public function getById(
		int $id,
		int $userId = 0,
		bool $withCounters = true,
		bool $withClientsData = true,
		bool $withExternalData = true,
		bool $withSkus = true,
	): Entity\Booking\Booking|null
	{
		// todo: needs refactoring, repository should not know about providers
		$provider = new BookingProvider();

		$collection = $provider->getList(
			gridParams: new GridParams(
				limit: 1,
				filter: new BookingFilter(['ID' => $id]),
				select: new BookingSelect([
					'CLIENTS',
					'RESOURCES',
					'EXTERNAL_DATA',
					'SKUS',
					'NOTE',
					'CLIENT_NOTE',
				])
			),
			userId: $userId
		);

		if ($withCounters)
		{
			$provider->withCounters($collection, $userId);
		}

		if ($withClientsData)
		{
			$provider->withClientsData($collection);
		}

		if ($withExternalData)
		{
			$provider->withExternalData($collection);
		}

		if ($withSkus)
		{
			$this->withSkus($collection);
		}

		return $collection->getFirstCollectionItem();
	}

	public function getByIdForManager(int $id): Entity\Booking\Booking|null
	{
		$select = new BookingSelect([
			'RESOURCES',
			'NOTE',
			'CLIENT_NOTE',
			'SKUS',
		]);

		$ormBooking = BookingTable::query()
			->setSelect(array_merge(['*'], $select->prepareSelect()))
			->where('ID', '=', $id)
			->exec()
			->fetchObject()
		;

		if (!$ormBooking)
		{
			return null;
		}

		$booking = $this->mapper->convertFromOrm($ormBooking);
		$this->withSkus(new BookingCollection($booking));

		return $booking;
	}

	public function save(Entity\Booking\Booking $booking): int
	{
		$ormBooking = $this->mapper->convertToOrm($booking);
		$result = $ormBooking->save();
		if (!$result->isSuccess())
		{
			throw new CreateBookingException($result->getErrors()[0]->getMessage());
		}

		$this->handleNote(
			$ormBooking,
			$booking->getNote(),
			$result->getId(),
			EntityType::Booking,
			NoteType::Manager,
		);

		$this->handleNote(
			$ormBooking,
			$booking->getClientNote(),
			$result->getId(),
			EntityType::Booking,
			NoteType::Client,
		);

		return $result->getId();
	}

	public function remove(int $id): void
	{
		$result = BookingTable::update($id, ['IS_DELETED' => 'Y']);
		if (!$result->isSuccess())
		{
			throw new RemoveBookingException($result->getErrors()[0]->getMessage());
		}
	}

	public function withSkus(BookingCollection $collection): self
	{
		$skuCollections = array_map(
			static fn(Booking $booking) => $booking->getSkuCollection(),
			$collection->getCollectionItems(),
		);

		$this->bookingSkuService->loadForCollection(...$skuCollections);

		return $this;
	}

	public function withClientData(BookingCollection $collection): self
	{
		$clientCollections = array_map(
			static fn(Booking $booking) => $booking->getClientCollection(),
			$collection->getCollectionItems(),
		);

		Container::getCrmClientDataLoader()->loadDataForCollection(...$clientCollections);

		return $this;
	}
}
