<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking;

use Bitrix\Crm\V2\Internal\Entity\Tracking\BuiltInSource;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Source;
use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceAddress;
use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceRecord;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingSourceNotFoundException;
use Bitrix\Crm\V2\Internal\Repository\Tracking\SourceRepository;

/**
 * The sources of the end-to-end analytics as the UI lists them: the stored records merged with the catalog
 * of the built-in sources and the built-in sources that have no record yet. The tables are read themselves:
 * nothing is cached and nothing is written, the email marketing source without a record is not saved.
 *
 * @internal
 */
final class SourceReader
{
	public function __construct(
		private readonly TrackingAccess $access = new TrackingAccess(),
		private readonly SourceCatalog $catalog = new SourceCatalog(),
		private readonly SourceRepository $sources = new SourceRepository(),
		private readonly SourceAssembler $assembler = new SourceAssembler(),
	)
	{
	}

	/**
	 * A page of the sources: by default the active records and the built-in sources without an active record
	 * (a source shown only with a record excepted); the archive is the archived records only. Every record of
	 * a code is a separate source.
	 *
	 * The built-in part of the list is assembled whole - it is bounded by the catalog and the records of its
	 * codes; the own sources are paged by the table, and the fields are read for the page only.
	 *
	 * @return list<Source> built-in sources in the order of the catalog, then own ones by ID
	 * @throws TrackingAccessDeniedException
	 */
	public function list(int $userId, bool $archived, int $limit, int $offset): array
	{
		$this->access->assertCanRead($userId);

		if ($limit <= 0 || $offset < 0)
		{
			return [];
		}

		$isActive = !$archived;
		$builtInPart = $this->builtInPart($isActive);
		$builtInPage = array_slice($builtInPart, $offset, $limit);
		$ownPage = $this->sources->listPageWithoutFields(
			$isActive,
			exceptIds: array_map(static fn(SourceRecord $record): int => $record->id, $this->recordsOf($builtInPart)),
			limit: $limit - count($builtInPage),
			offset: max(0, $offset - count($builtInPart)),
		);

		$records = [];
		foreach ($this->sources->withFields([...$this->recordsOf($builtInPage), ...$ownPage]) as $record)
		{
			$records[$record->id] = $record;
		}

		$sources = [];
		foreach ($builtInPage as ['record' => $record, 'builtIn' => $builtIn])
		{
			$sources[] = $record !== null
				? $this->assembler->fromRecord($records[$record->id], $builtIn)
				: $this->assembler->unconfigured($builtIn)
			;
		}
		foreach ($ownPage as $record)
		{
			$sources[] = $this->assembler->fromRecord($records[$record->id], null);
		}

		return $this->assembler->sort($sources, $this->catalog);
	}

	/**
	 * @throws TrackingAccessDeniedException
	 * @throws TrackingSourceNotFoundException
	 */
	public function get(int $userId, SourceAddress $address): Source
	{
		$this->access->assertCanRead($userId);

		return $this->locate($address)['source'];
	}

	/**
	 * Finds the source the address names, without checking any right - for the services that check their
	 * own. A code names its active record with the least ID, else its archived record with the least ID,
	 * else the built-in source without a record.
	 *
	 * @return array{source: Source, record: SourceRecord|null, builtIn: BuiltInSource|null}
	 * @throws TrackingSourceNotFoundException the record is missing, or the code is not a built-in source
	 * available on the portal
	 */
	public function locate(SourceAddress $address): array
	{
		if ($address->code !== null)
		{
			return $this->locateByCode($address);
		}

		$record = $this->sources->getRecord((int)$address->id) ?? throw new TrackingSourceNotFoundException($address);
		$builtIn = $record->code !== '' ? $this->catalog->find($record->code) : null;

		return [
			'source' => $this->assembler->fromRecord($record, $builtIn),
			'record' => $record,
			'builtIn' => $builtIn,
		];
	}

	/**
	 * @return array{source: Source, record: SourceRecord|null, builtIn: BuiltInSource}
	 */
	private function locateByCode(SourceAddress $address): array
	{
		$builtIn = $this->catalog->find((string)$address->code) ?? throw new TrackingSourceNotFoundException($address);

		$records = $this->sources->listRecordsByCode($builtIn->code->value);
		$active = array_values(array_filter($records, static fn(SourceRecord $record): bool => $record->isActive));
		$record = $active[0] ?? $records[0] ?? null;

		return [
			'source' => $record !== null
				? $this->assembler->fromRecord($record, $builtIn)
				: $this->assembler->unconfigured($builtIn),
			'record' => $record,
			'builtIn' => $builtIn,
		];
	}

	/**
	 * The built-in sources of the list in its order: the records of the codes of the catalog, in the order of the
	 * catalog and by ID, their fields not read; by default a code without an active record is listed as the
	 * built-in source without a record, unless it is shown only with one.
	 *
	 * @return list<array{record: SourceRecord|null, builtIn: BuiltInSource}>
	 */
	private function builtInPart(bool $isActive): array
	{
		$catalog = $this->catalogByCode();

		$recordsByCode = [];
		foreach ($this->sources->listByCodesWithoutFields(array_keys($catalog), $isActive) as $record)
		{
			// The table may find a code equal in another case; such a record is an own source, as in the UI.
			if (isset($catalog[$record->code]))
			{
				$recordsByCode[$record->code][] = $record;
			}
		}

		$part = [];
		foreach ($catalog as $code => $builtIn)
		{
			foreach ($recordsByCode[$code] ?? [] as $record)
			{
				$part[] = ['record' => $record, 'builtIn' => $builtIn];
			}

			if ($isActive && !isset($recordsByCode[$code]) && !$builtIn->showOnlyExisting)
			{
				$part[] = ['record' => null, 'builtIn' => $builtIn];
			}
		}

		return $part;
	}

	/**
	 * @param list<array{record: SourceRecord|null, builtIn: BuiltInSource}> $part
	 * @return list<SourceRecord>
	 */
	private function recordsOf(array $part): array
	{
		$records = [];
		foreach ($part as ['record' => $record])
		{
			if ($record !== null)
			{
				$records[] = $record;
			}
		}

		return $records;
	}

	/**
	 * @return array<string, BuiltInSource> in the order of the catalog
	 */
	private function catalogByCode(): array
	{
		$catalog = [];
		foreach ($this->catalog->list() as $builtIn)
		{
			$catalog[$builtIn->code->value] = $builtIn;
		}

		return $catalog;
	}
}
