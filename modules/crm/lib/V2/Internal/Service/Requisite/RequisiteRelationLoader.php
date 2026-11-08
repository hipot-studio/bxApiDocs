<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Requisite;

use Bitrix\Crm\V2\Internal\Entity\Requisite\Requisite;
use Bitrix\Crm\V2\Internal\Entity\Requisite\RequisiteAddress;
use Bitrix\Crm\V2\Internal\Entity\Requisite\RequisiteBankDetail;
use Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteAddressRepository;
use Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteBankDetailRepository;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;

/**
 * The records that hang off a requisite - its addresses and its bank details - read for a whole page of
 * requisites at once.
 *
 * The cost of a page is what this class exists for: the same records read requisite by requisite would
 * cost as many queries as the page is long, and a page of fifty requisites would ask the storage a
 * hundred times. Here a page costs a fixed number of queries whatever its length, and a list of
 * identifiers too long for one condition is read in parts rather than in one query the storage would
 * have to parse a thousand values out of.
 *
 * Only what was asked for is read. A relation the caller did not name costs nothing at all - its table
 * is not read, not read and discarded - which is what takes the cost off the requests that want the
 * requisites alone.
 *
 * **"Not loaded" and "no values" are different answers, and the shape of the result says which is
 * which:**
 *
 * - a relation that was not asked for has no key in the result. The caller leaves it out of whatever it
 *   builds, because it knows nothing about it rather than knowing it is empty;
 * - a relation that was asked for has a key, and under it an entry for **every** requisite of the page -
 *   an empty array for the ones that have no such records.
 *
 * Confusing the two would start answering empty collections where a client asked for none, and a client
 * cannot tell "you have no addresses" from "I did not ask" once that happens.
 *
 * The relations are answered as a map rather than attached to the requisites themselves: a
 * {@see Requisite} is read-only by construction and does not change after it is put together, so what
 * comes back is keyed by the identifier of the requisite it belongs to and joining the two is the
 * caller's - it is the one that knows what it is building.
 *
 * Knows nothing of transport, of DTOs or of the names a client speaks: it is given internal records and
 * the names of relations of the area.
 *
 * @internal
 */
final class RequisiteRelationLoader
{
	/** The addresses of a requisite, {@see RequisiteAddress}. */
	public const RELATION_ADDRESSES = 'addresses';

	/** The bank details of a requisite, {@see RequisiteBankDetail}. */
	public const RELATION_BANK_REQUISITES = 'bankRequisites';

	/**
	 * How many requisites one query answers for. A page is normally far shorter than this, so the whole
	 * page is one query; the limit exists for the pages that are not - a condition listing a thousand
	 * identifiers is a query the storage parses longer than it answers.
	 */
	private const ID_CHUNK_SIZE = 100;

	public function __construct(
		private readonly RequisiteAddressRepository $addresses = new RequisiteAddressRepository(),
		private readonly RequisiteBankDetailRepository $bankDetails = new RequisiteBankDetailRepository(),
	)
	{
	}

	/**
	 * The relations $relations names, for every requisite of $requisites.
	 *
	 * The order within a collection is the order its repository fixed - addresses by type, bank details
	 * as the card of a requisite orders them - so the same request answers the same order twice.
	 *
	 * @param Requisite[] $requisites the page the relations are read for; the same requisite twice is
	 *        read once.
	 * @param string[] $relations names of relations of this class, `RELATION_*`.
	 * @return array<string, array<int, RequisiteAddress[]|RequisiteBankDetail[]>> the requested relations
	 *         by name, each an entry per requisite identifier; a relation that was not requested is
	 *         absent from the result entirely.
	 * @throws ArgumentException a relation this class does not know. Dropping it silently would answer a
	 *         page without a relation the caller believes it asked for.
	 * @throws SystemException a failure of one of the queries.
	 */
	public function load(array $requisites, array $relations): array
	{
		$requested = self::requireRelations($relations);
		if ($requested === [])
		{
			return [];
		}

		$ids = self::collectIds($requisites);

		$loaded = [];
		foreach ($requested as $relation)
		{
			// Every requisite of the page gets an entry before anything is read: what the storage does not
			// answer for is a requisite without such records rather than a requisite left unread.
			$loaded[$relation] = array_fill_keys($ids, []);
		}

		foreach (array_chunk($ids, self::ID_CHUNK_SIZE) as $chunk)
		{
			if (isset($loaded[self::RELATION_ADDRESSES]))
			{
				foreach ($this->addresses->findByRequisiteIds($chunk) as $address)
				{
					$loaded[self::RELATION_ADDRESSES][$address->requisiteId][] = $address;
				}
			}

			if (isset($loaded[self::RELATION_BANK_REQUISITES]))
			{
				foreach ($this->bankDetails->findByRequisiteIds($chunk) as $bankDetail)
				{
					$loaded[self::RELATION_BANK_REQUISITES][$bankDetail->requisiteId][] = $bankDetail;
				}
			}
		}

		return $loaded;
	}

	/**
	 * @param string[] $relations
	 * @return string[] the relations to read, each once and in the order this class reads them.
	 * @throws ArgumentException
	 */
	private static function requireRelations(array $relations): array
	{
		$known = [self::RELATION_ADDRESSES, self::RELATION_BANK_REQUISITES];

		$unknown = array_diff($relations, $known);
		if ($unknown !== [])
		{
			throw new ArgumentException(
				sprintf('Unknown relation of a requisite: %s', implode(', ', $unknown)),
				'relations',
			);
		}

		return array_values(array_intersect($known, $relations));
	}

	/**
	 * @param Requisite[] $requisites
	 * @return int[]
	 */
	private static function collectIds(array $requisites): array
	{
		$ids = [];
		foreach ($requisites as $requisite)
		{
			$ids[$requisite->id] = $requisite->id;
		}

		return array_values($ids);
	}
}
