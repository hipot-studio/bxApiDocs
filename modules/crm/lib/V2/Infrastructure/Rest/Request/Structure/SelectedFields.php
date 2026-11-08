<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

/**
 * What a read answers with, in the two parts a read is made of: the scalar fields of the record itself
 * and the fields of the collections hanging off it.
 *
 * The two are kept apart because they are read apart - the scalars come from the record, a nested
 * collection from a read of its own - and a consumer has to be able to tell "the client asked for the
 * addresses" from "the client asked for a field called addresses". It is {@see RequestSelectedFields}
 * that works both parts out; this is only the answer it gives.
 *
 * The scalar part is never empty, so a read is never left without a name to answer under: a client that
 * asked for a collection alone is answered the key of the record along with it, or it would have no way
 * of telling which record the collection belongs to.
 */
final readonly class SelectedFields
{
	/**
	 * @param string[] $scalarFields names of the fields of the record itself, never empty.
	 * @param array<string, string[]> $nestedFields name of the collection => the names asked for inside
	 *        it; empty when the client asked for no collection.
	 */
	public function __construct(private array $scalarFields, private array $nestedFields)
	{
	}

	/** @return string[] never empty. */
	public function getScalarFields(): array
	{
		return $this->scalarFields;
	}

	/** @return array<string, string[]> name of the collection => the names asked for inside it. */
	public function getNestedFields(): array
	{
		return $this->nestedFields;
	}
}
