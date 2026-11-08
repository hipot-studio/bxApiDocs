<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\HumanResources\Integration\UI\DepartmentProvider;
use Bitrix\HumanResources\Internals\Service\Container;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

/**
 * The dialog side of the node binding of the employee triggers: the field the binding is picked in, the
 * turn a saved binding makes into that field and back, and the carrier of the part of it no selector can
 * show.
 *
 * Both triggers bind the very same way and live in different modules, so this belongs to neither of them:
 * a second copy would let the field of one trigger, the tags it shows and the codes it saves drift away
 * from the other. What stays with a trigger is what the trigger names - the property the binding is saved
 * under and the field its carrier travels through; the rules of applying it are {@see StructureNodeFilter}.
 */
final class StructureNodeBinding
{
	/**
	 * Nodes each code of a binding names, kept for the request: one rendering of the dialog reads the same
	 * binding four times over, and a code of the old structure costs a lookup on every one of them. The
	 * scope is the class and not a call, the way {@see StructureNodeFilter} keeps its answers, and the key
	 * is the code itself - a code names the same nodes whoever asks. The answer keeps which code each node
	 * came from: over a flat list of node ids {@see self::carriedCodes()} could not tell a code that
	 * resolved into nothing from one that did not.
	 *
	 * @var array<string, list<array{int, bool}>>
	 */
	private static array $nodesByCode = [];

	/**
	 * The entity the field of the binding is a selector of, options and all: the very same declaration is
	 * read by the field of the dialog and by the provider the tags of the saved nodes are built with, so
	 * that the two show one and the same tree.
	 */
	public static function selectorEntity(): array
	{
		return [
			'id' => DepartmentProvider::ENTITY_ID,
			'options' => self::selectorEntityOptions(),
		];
	}

	/**
	 * The binding as the selector of the dialog reads it: an expression first, so that the control puts it
	 * into the field of expressions, and then the identifiers of the picked nodes.
	 *
	 * @param mixed $storedValue Binding as the template holds it.
	 *
	 * @return list<string>
	 */
	public static function toDialogValue(mixed $storedValue): array
	{
		$expressions = [];
		foreach (self::stringList($storedValue) as $code)
		{
			if (\CBPActivity::isExpression($code))
			{
				$expressions[] = $code;
			}
		}

		return array_values(array_unique(array_merge($expressions, self::selectorNodeIds($storedValue))));
	}

	/**
	 * Tags of the picked nodes, built by the provider of the org structure with the very options the field
	 * declares: the tag then carries the title and the look the node has in the tree of the selector, and,
	 * unlike the preselect, no right to view is applied on top of it.
	 *
	 * @param mixed $storedValue Binding as the template holds it.
	 */
	public static function selectorItems(mixed $storedValue): array
	{
		$selectorIds = self::selectorNodeIds($storedValue);
		if (!$selectorIds || !Loader::includeModule('ui'))
		{
			return [];
		}

		$items = (new DepartmentProvider(self::selectorEntityOptions()))->getItems($selectorIds);

		return array_map(static fn($item) => $item->toArray(), $items);
	}

	/**
	 * The part of the binding no identifier of the selector can carry, packed for the one field of the form
	 * it travels through and back.
	 *
	 * @param mixed $storedValue Binding as the template holds it.
	 */
	public static function carriedValue(mixed $storedValue): string
	{
		return self::pack(self::carriedCodes($storedValue));
	}

	/**
	 * The binding the dialog sends back, written in the codes a template stores (DTO-01).
	 *
	 * An expression reaches the saved value through the field of expressions alone. The control renders the
	 * expression it was shown both as a hidden item of the selector and as the text of that field
	 * ({@see \Bitrix\Bizproc\BaseType\EntitySelector::renderControlMultiple()}), and only the text is
	 * editable, so the hidden copy is dropped as the older of the two.
	 *
	 * What the field holds is taken whatever it is: the engine passes an expression through and drops the
	 * rest ({@see \Bitrix\Bizproc\BaseType\Base::extractValueMultiple()}), so an access code and a bare
	 * node id typed there are read off the raw values of the form, and what may be taken at all is decided
	 * in {@see StructureNodeValue::toAccessCodes()}.
	 *
	 * The carrier brings back the part the selector cannot show, so a binding that is not empty is never
	 * saved empty. The one exception is a code naming an employee by hand, dropped here whatever else the
	 * binding holds: the carrier travels through the form, so dropping it on the way in, and not only where
	 * the carrier is packed, is what makes the rule hold. A binding of such codes alone saves empty, which
	 * the caption of the field states.
	 *
	 * @param mixed $picked Value of the field of the binding, as the form sends it.
	 * @param mixed $typed What was typed into the field of expressions by hand.
	 * @param mixed $carried Value of the carrier, as the form sends it back.
	 *
	 * @return list<string>
	 */
	public static function toStoredValue(mixed $picked, mixed $typed, mixed $carried): array
	{
		$values = array_values(array_filter(
			self::stringList($picked),
			static fn(string $code) => !\CBPActivity::isExpression($code),
		));

		if (is_string($typed))
		{
			$values[] = $typed;
		}

		return array_values(array_unique(array_merge(
			StructureNodeValue::toAccessCodes($values),
			StructureNodeValue::withoutUserCodes(self::unpack($carried)),
		)));
	}

	/**
	 * Drops the answers of the org structure kept for the request. Intended for use in tests.
	 */
	public static function resetLookupCache(): void
	{
		self::$nodesByCode = [];
	}

	/**
	 * `allowFlatDepartments` is not passed at all: both branches of the provider creating the "members of
	 * the node itself" variant require the mode with employees in the tree, so here the key would change
	 * nothing while making the variant look available.
	 */
	private static function selectorEntityOptions(): array
	{
		return [
			// A team is bound exactly like a department, and the provider answers with departments alone
			// unless both are asked for.
			'includedNodeEntityTypes' => ['DEPARTMENT', 'TEAM'],
			// Employees have no place in the tree: the binding names nodes.
			'selectMode' => 'departmentsOnly',
			// Passed on purpose, and false on purpose: the options of the provider read the key with no
			// `??`, and access to the designer of scenarios is right enough to see the whole structure.
			'restricted' => false,
		];
	}

	/**
	 * Identifiers the selector carries the nodes of the binding under (DTO-01).
	 *
	 * @return list<string>
	 */
	private static function selectorNodeIds(mixed $storedValue): array
	{
		$codes = self::stringList($storedValue);
		self::rememberPickedNodes($codes);

		$ids = [];
		foreach ($codes as $code)
		{
			foreach (self::pickedNodes($code) as [$nodeId, $withSubordinates])
			{
				$ids[] = StructureNodeValue::toSelectorId($nodeId, $withSubordinates);
			}
		}

		return array_values(array_unique($ids));
	}

	/**
	 * The part of the binding the selector cannot show, minus the codes naming an employee by hand: those
	 * are not carried at all, so the dialog does not ship a value it would refuse on the way back.
	 *
	 * @return list<string>
	 */
	private static function carriedCodes(mixed $storedValue): array
	{
		self::rememberPickedNodes(self::stringList($storedValue));

		$codes = [];
		foreach (self::stringList($storedValue) as $code)
		{
			// An expression is shown in the field of expressions and comes back from it, so carrying it
			// here as well would save it twice.
			if (\CBPActivity::isExpression($code) || self::pickedNodes($code) !== [])
			{
				continue;
			}

			$codes[] = $code;
		}

		$codes = StructureNodeValue::withoutUserCodes($codes);

		return $codes;
	}

	/**
	 * Nodes one code of the binding names, each with the kind of pick it was made with. Answered out of what
	 * {@see self::rememberPickedNodes()} has already read; a code nobody asked about beforehand is answered
	 * on its own.
	 *
	 * @return list<array{int, bool}>
	 */
	private static function pickedNodes(string $code): array
	{
		$key = mb_strtolower($code);
		if (!array_key_exists($key, self::$nodesByCode))
		{
			self::rememberPickedNodes([$code]);
		}

		return self::$nodesByCode[$key];
	}

	/**
	 * Reads the nodes of every code of a binding the request has not seen yet, asking the org structure once
	 * for all of them. The reading keeps the code each node came from: a section of the old structure that
	 * resolved into nothing is what tells the caller to carry the code over untouched, and over a flat list
	 * of node ids that answer is the same as a section which resolved. A binding naming no such section
	 * costs no query at all.
	 *
	 * @param list<string> $codes
	 */
	private static function rememberPickedNodes(array $codes): void
	{
		$asked = [];
		foreach ($codes as $code)
		{
			$key = mb_strtolower($code);
			if (!array_key_exists($key, self::$nodesByCode))
			{
				$asked[$key] = $key;
			}
		}

		if (!$asked)
		{
			return;
		}

		$sectionsToRead = [];
		$sectionOfAsk = [];
		foreach ($asked as $key)
		{
			$normalized = StructureNodeValue::normalizeCodes([$key]);

			$nodes = [];
			foreach ([true, false] as $withSubordinates)
			{
				foreach (StructureNodeValue::nodeIds($normalized, $withSubordinates) as $nodeId)
				{
					$nodes[] = [$nodeId, $withSubordinates];
				}

				$sectionAccessCodes = StructureNodeValue::sectionAccessCodes($normalized, $withSubordinates);
				foreach ($sectionAccessCodes as $sectionAccessCode)
				{
					$sectionsToRead[$sectionAccessCode] = $sectionAccessCode;
					$sectionOfAsk[] = [$key, $withSubordinates, $sectionAccessCode];
				}
			}

			self::$nodesByCode[$key] = $nodes;
		}

		if (!$sectionsToRead)
		{
			return;
		}

		$nodeIdsBySection = Container::getNodeAccessCodeService()
			->getNodeIdsGroupedByAccessCode(array_values($sectionsToRead))
		;

		foreach ($sectionOfAsk as [$key, $withSubordinates, $sectionAccessCode])
		{
			foreach ($nodeIdsBySection[$sectionAccessCode] ?? [] as $nodeId)
			{
				self::$nodesByCode[$key][] = [$nodeId, $withSubordinates];
			}
		}
	}

	/**
	 * The carried part travels through one field of the form, and the shape of that field is not ours to
	 * choose: the settings panel renders a hidden property as a single input holding one string. It is
	 * packed here and unpacked by {@see self::unpack()}, and nowhere else.
	 *
	 * JSON because the codes carried here include strings nobody recognised, so no separator can be proved
	 * absent from them; base64 over it because that leaves the field a string of `A-Za-z0-9+/=`, which the
	 * proactive filter of the `security` module ({@see \Bitrix\Security\Filter\Request}) passes through
	 * instead of rewriting as dangerous.
	 *
	 * @param list<string> $codes
	 */
	private static function pack(array $codes): string
	{
		return $codes ? base64_encode(Json::encode($codes)) : '';
	}

	/**
	 * @return list<string>
	 */
	private static function unpack(mixed $packed): array
	{
		if (!is_string($packed) || $packed === '')
		{
			return [];
		}

		$json = base64_decode($packed, true);
		if ($json === false)
		{
			return [];
		}

		try
		{
			$codes = Json::decode($json);
		}
		catch (ArgumentException)
		{
			return [];
		}

		return is_array($codes) ? self::stringList($codes) : [];
	}

	/**
	 * @return list<string>
	 */
	private static function stringList(mixed $value): array
	{
		$strings = [];
		foreach (\CBPHelper::flatten($value) as $item)
		{
			if (is_string($item) && $item !== '')
			{
				$strings[] = $item;
			}
		}

		return $strings;
	}
}
