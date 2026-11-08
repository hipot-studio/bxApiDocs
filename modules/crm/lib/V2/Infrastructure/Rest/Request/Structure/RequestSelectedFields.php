<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * The fields a read answers with, and the single place that rule is stated.
 *
 * Three answers in one order: what the client asked for, the fields its scope is restricted to when it
 * asked for nothing, and one named field when neither applies. An empty asked-for list is nothing asked
 * for rather than everything, and is answered as one that carried no list at all. The scalar part of the
 * answer is never empty, so a read is never asked for "everything" by accident and never answers a record
 * without a name to answer it under.
 *
 * The rule belongs to the REST of the CRM rather than to any one group of methods: it decides the
 * composition of every response built field by field, and two copies of it would mean one group quietly
 * answering differently from another. Only the fallback is the group's own - the field it answers with
 * when nothing was asked for and no scope narrows the answer.
 *
 * ### Asking for a collection is asking for something
 *
 * A request carries two kinds of asking ({@see SelectStructure}): the scalar fields of the top level, and
 * the fields of the collections hanging off it - a collection named on its own stands for all the scalar
 * fields inside it, a name qualified with a dot for one of them. A request that names a collection alone
 * has asked for something, so it is not an empty one, and it is answered with what it asked for plus the
 * key of the record - which is the one thing a client needs to tell the collections of one record from
 * those of another.
 *
 * That is why the answer comes as {@see SelectedFields} rather than as a list of names. A request that
 * named no collection gets an empty second part and a first part that is exactly the list this rule
 * answered with before collections were part of it, which is what {@see self::resolve()} keeps handing
 * out for the reads that have no collections to answer.
 *
 * The narrowing by scope applies to the collections as well, and it applies before this rule sees them:
 * a collection outside the fields the scope allows is refused by name as the request is parsed. What
 * reaches the fallback below is therefore the scope's own list of top-level names, and a read narrowed
 * by scope answers no collection unless the client asked for one.
 *
 * ### Asking for a user field alone is still read as asking for nothing
 *
 * The emptiness test below looks at the scalar fields and at the collections, and at nothing else. A
 * request naming only user fields of the portal is therefore taken for one that named nothing and is
 * answered the fallback instead of what it asked for: {@see SelectStructure} keeps those apart in
 * {@see SelectStructure::getUserFields()}, which neither part of this rule reads. No DTO answered here
 * publishes a user field today, so the case is unreachable for every current consumer - the first one
 * that publishes one has to widen the test here rather than work around it where it answers.
 *
 * @see SelectStructure the structure the asked-for list comes in.
 */
final class RequestSelectedFields
{
	/**
	 * The scalar fields of the top level alone, for a read that answers with no collection.
	 *
	 * The same rule as {@see self::resolveStructured()} and the same answer it gives - a read that has
	 * collections to answer asks there instead, or it would be handed the key of the record where it
	 * asked for the addresses.
	 *
	 * @param string $defaultFieldName the field a read falls back to; the identifier of the entity, as
	 *        the group that answers names it.
	 * @return string[] field names, never empty.
	 */
	public static function resolve(Request $request, string $defaultFieldName): array
	{
		return self::resolveStructured($request, $defaultFieldName)->getScalarFields();
	}

	/**
	 * Both parts of what a read answers with: the scalar fields of the record and the fields asked for
	 * inside each collection of it.
	 *
	 * @param string $defaultFieldName the field a read falls back to; the identifier of the entity, as
	 *        the group that answers names it.
	 */
	public static function resolveStructured(Request $request, string $defaultFieldName): SelectedFields
	{
		$select = $request->select ?? null;
		$askedFor = $select?->getList() ?? [];
		$askedForNested = self::nestedFieldsOf($select);

		if ($askedFor !== [] || $askedForNested !== [])
		{
			return new SelectedFields($askedFor === [] ? [$defaultFieldName] : $askedFor, $askedForNested);
		}

		$scope = $request->getOptions()['scope'] ?? null;
		$scopeFields = $scope?->fields ?? [];

		return new SelectedFields($scopeFields === [] ? [$defaultFieldName] : $scopeFields, []);
	}

	/**
	 * The structured list holds the two kinds of asking in one array, the scalar fields of the top level
	 * under keys of their own position and the collections under their names, so the names are what tells
	 * them apart.
	 *
	 * @return array<string, string[]>
	 */
	private static function nestedFieldsOf(?SelectStructure $select): array
	{
		$nestedFields = [];
		foreach ($select?->getStructuredList() ?? [] as $fieldName => $nestedFieldNames)
		{
			if (is_string($fieldName))
			{
				$nestedFields[$fieldName] = $nestedFieldNames;
			}
		}

		return $nestedFields;
	}
}
