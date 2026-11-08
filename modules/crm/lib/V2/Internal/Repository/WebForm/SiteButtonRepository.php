<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm;

use Bitrix\Crm\SiteButton\Internals\ButtonTable;
use Bitrix\Crm\SiteButton\Manager;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\SiteButtonItem;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\SiteButtonItemCollection;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormSort;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;

/**
 * @internal
 */
final class SiteButtonRepository
{
	private const MAX_PAGE_SIZE = 1000;

	/** Seconds the map of the chats lives for while no widget is written to. */
	private const OPEN_LINE_MAP_CACHE_TTL = 60;

	/**
	 * Slots of the widget composition that can hold a CRM form. A widget keeps at most one form
	 * per slot, so the forms of a widget are the union of its slots.
	 */
	private const FORM_SLOTS = [
		Manager::ENUM_TYPE_CRM_FORM,
		Manager::ENUM_TYPE_WHATSAPP,
		Manager::ENUM_TYPE_CALLBACK,
	];

	/**
	 * Returns a stable page of active site widgets projected against the requested form.
	 *
	 * The composition of a widget is read straight off its serialized column: the channel manager
	 * loads every active form of the portal with its fields just to name the linked entities, and
	 * the page needs their ids only.
	 *
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function findAll(int $formId, WebFormSort $sort, int $limit, int $offset): SiteButtonItemCollection
	{
		if ($limit < 1 || $limit > self::MAX_PAGE_SIZE)
		{
			throw new ArgumentException(sprintf('Invalid site button page limit `%d`', $limit), 'limit');
		}
		if ($offset < 0)
		{
			throw new ArgumentException(sprintf('Invalid site button page offset `%d`', $offset), 'offset');
		}

		$rows =
			ButtonTable::query()
				->setSelect(['ID', 'NAME', 'IS_SYSTEM', 'ITEMS'])
				->where('ACTIVE', 'Y')
				->setOrder(['ID' => $sort->getIdDirection()])
				->setLimit($limit)
				->setOffset($offset)
				->fetchAll()
		;

		$items = [];
		foreach ($rows as $row)
		{
			$formIds = self::readFormIds($row);
			$items[] = new SiteButtonItem(
				id: (int)$row['ID'],
				name: (string)($row['NAME'] ?? ''),
				system: self::isFlagSet($row['IS_SYSTEM'] ?? null),
				bound: in_array($formId, $formIds, true),
				formIds: $formIds,
			);
		}

		return new SiteButtonItemCollection(...$items);
	}

	/**
	 * Returns the id of the active widget showing every open line chat, keyed by the chat id.
	 *
	 * The chat of a widget lives in the same serialized column as its forms, so a single pass over
	 * the active widgets answers for every chat at once. A chat shown by several widgets gets the
	 * first of them by id.
	 *
	 * The pass reads every active widget whatever the page asked for, because the chat of a widget
	 * cannot be named in the query, so the read is cached. The cache belongs to the widget table and
	 * the ORM drops it on every write of one, which is how a widget of the portal is ever written;
	 * the short life of the cache is what is left to cover a write that reaches the table otherwise.
	 *
	 * @return array<int, int>
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function getActiveIdByOpenLineId(): array
	{
		$rows =
			ButtonTable::query()
				->setSelect(['ID', 'ITEMS'])
				->where('ACTIVE', 'Y')
				->setOrder(['ID' => WebFormSort::ASC])
				->setCacheTtl(self::OPEN_LINE_MAP_CACHE_TTL)
				->fetchAll()
		;

		$idByOpenLineId = [];
		foreach ($rows as $row)
		{
			$openLineId = self::readOpenLineId($row);
			if ($openLineId === null || isset($idByOpenLineId[$openLineId]))
			{
				continue;
			}

			$idByOpenLineId[$openLineId] = (int)$row['ID'];
		}

		return $idByOpenLineId;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return int[]
	 */
	private static function readFormIds(array $row): array
	{
		$formIds = [];
		foreach (self::FORM_SLOTS as $slot)
		{
			// A slot keeps the form it was unlinked from, so only an active slot is a link.
			$item = self::readSlot($row, $slot);
			if ($item === null || !self::isFlagSet($item['ACTIVE'] ?? null))
			{
				continue;
			}

			$formId = (int)($item['EXTERNAL_ID'] ?? 0);
			if ($formId > 0)
			{
				$formIds[$formId] = true;
			}
		}

		return array_keys($formIds);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function readOpenLineId(array $row): ?int
	{
		// A slot keeps the chat it was switched off from, so only an active slot is a link.
		$item = self::readSlot($row, Manager::ENUM_TYPE_OPEN_LINE);
		if ($item === null || !self::isFlagSet($item['ACTIVE'] ?? null))
		{
			return null;
		}

		$openLineId = (int)($item['EXTERNAL_ID'] ?? 0);

		return $openLineId > 0 ? $openLineId : null;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return null|array<string, mixed>
	 */
	private static function readSlot(array $row, string $slot): ?array
	{
		$items = $row['ITEMS'] ?? null;
		$item = is_array($items) ? ($items[$slot] ?? null) : null;

		return is_array($item) ? $item : null;
	}

	private static function isFlagSet(mixed $value): bool
	{
		return $value === true || $value === 'Y' || $value === 1 || $value === '1';
	}
}
