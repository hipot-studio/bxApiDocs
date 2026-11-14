<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking;

use Bitrix\Crm\Tracking\Internals\SourceFieldTable;
use Bitrix\Crm\V2\Internal\Entity\Tracking\BuiltInSource;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Source;
use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceRecord;

/**
 * Builds the sources the user sees from the stored records and the catalog of the built-in sources, by the
 * rules the legacy list of the actual sources merges them: the name of a built-in source is stored, its color,
 * its flags and the domains or UTM sources the product defines come from the catalog.
 *
 * @internal
 */
final class SourceAssembler
{
	/** The color legacy gives an own source that has none. */
	private const OWN_DEFAULT_COLOR = '#55d0e0';

	/**
	 * @param BuiltInSource|null $builtIn the catalog entry of the code of the record; null when the record is
	 * own or its code is not available on the portal - such a record is shown as an own source
	 */
	public function fromRecord(SourceRecord $record, ?BuiltInSource $builtIn): Source
	{
		$storedRefDomains = $record->fields[SourceFieldTable::FIELD_REF_DOMAIN] ?? [];
		$storedUtmSources = $record->fields[SourceFieldTable::FIELD_UTM_SOURCE] ?? [];

		if ($builtIn === null)
		{
			return new Source(
				id: $record->id,
				code: null,
				name: $record->name,
				color: $record->color !== '' ? $record->color : self::OWN_DEFAULT_COLOR,
				isActive: $record->isActive,
				isConfigurable: true,
				isConfigured: $storedUtmSources !== [] || $storedRefDomains !== [],
				refDomains: $storedRefDomains,
				utmSources: $storedUtmSources,
			);
		}

		$refDomains = $builtIn->refDomains ?? $storedRefDomains;
		$utmSources = $builtIn->utmSources ?? $storedUtmSources;

		return new Source(
			id: $record->id,
			code: $builtIn->code,
			name: $record->name,
			color: $builtIn->color,
			isActive: $record->isActive,
			isConfigurable: $builtIn->code->isConfigurable(),
			isConfigured: !$builtIn->code->isConfigurable() || $utmSources !== [] || $refDomains !== [],
			refDomains: $refDomains,
			utmSources: $utmSources,
		);
	}

	/**
	 * A built-in source that has no record: nothing of it is set up, except organic traffic and email
	 * marketing, which the product sets up itself.
	 */
	public function unconfigured(BuiltInSource $builtIn): Source
	{
		return new Source(
			id: null,
			code: $builtIn->code,
			name: $builtIn->defaultName,
			color: $builtIn->color,
			isActive: true,
			isConfigurable: $builtIn->code->isConfigurable(),
			isConfigured: !$builtIn->code->isConfigurable(),
			refDomains: $builtIn->refDomains ?? [],
			utmSources: $builtIn->utmSources ?? [],
		);
	}

	/**
	 * Puts the built-in sources first, in the order of the catalog, the records of one code by ID, and the own
	 * sources after them by ID. The legacy comparator is not reproduced: it never says "less", so its order
	 * depends on the sorting algorithm.
	 *
	 * @param list<Source> $sources
	 * @return list<Source>
	 */
	public function sort(array $sources, SourceCatalog $catalog): array
	{
		$weights = [];
		foreach ($catalog->list() as $index => $builtIn)
		{
			$weights[$builtIn->code->value] = $index;
		}
		$ownWeight = count($weights);

		$weightOf = static fn(Source $source): int =>
			$source->code === null ? $ownWeight : $weights[$source->code->value] ?? $ownWeight
		;

		usort(
			$sources,
			static fn(Source $a, Source $b): int => [$weightOf($a), $a->id ?? 0] <=> [$weightOf($b), $b->id ?? 0],
		);

		return $sources;
	}
}
