<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item;

/**
 * Outcome of resolving an item select spec once: the storage columns to read and the custom-field
 * (`UF_*`) names to hydrate. Knows nothing about the reader or its permissions - names only.
 *
 * Both lists are de-duplicated and reindexed on construction, so a caller may append freely while
 * collecting them.
 *
 * @internal
 */
final class ResolvedSelect
{
	/** @var string[] ORM column names, or the single `'*'` token for a wildcard read */
	public readonly array $ormNames;

	/** @var string[] custom-field names to hydrate */
	public readonly array $ufNames;

	/**
	 * @param string[] $ormNames
	 * @param string[] $ufNames
	 */
	public function __construct(array $ormNames, array $ufNames)
	{
		$this->ormNames = array_values(array_unique($ormNames));
		$this->ufNames = array_values(array_unique($ufNames));
	}
}
