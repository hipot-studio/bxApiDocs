<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\HumanResources\Type\AccessCodeType;

/**
 * The value of the node binding property of the employee triggers, read and written in one dictionary.
 *
 * A template saves the binding as a flat list of access codes, while the entity selector of the dialog
 * speaks in identifiers of its own: a bare node id stands for the node together with its subordinates, and
 * the `:F` mark for the members of the node itself. Both sides of that correspondence live here alone, so
 * that the setting the dialog saves and the setting the filter reads cannot drift apart.
 *
 * Codes of the old structure carry a section id instead of a node id, so the node they name is known only
 * after the org structure translates them; the translation itself belongs to the caller, which is the only
 * side allowed to reach the structure.
 */
final class StructureNodeValue
{
	private const NODE_CODE_PATTERN = '/^group_hr(r?)(\d+)$/';
	private const SECTION_CODE_PATTERN = '/^group_d(r?)(\d+)$/';
	private const USER_CODE_PATTERN = '/^user_(\d+)$/';
	private const SELECTOR_IDENTIFIER_PATTERN = '/^(\d+)(:F)?$/i';

	private const SUBORDINATES_CODE_MARK = 'r';
	private const NODE_CODE_PREFIX = 'group_hr';
	private const OWN_MEMBERS_IDENTIFIER_MARK = ':F';

	/**
	 * Access codes of the value, as the engine writes them: lower case, one flat list.
	 *
	 * The list is read off the binding the activity has already resolved, so a string still shaped like an
	 * expression here is one the activity could not compute: the rules of a trigger are checked on a stub
	 * workflow with no parameters and no variables. It is dropped, and the caller tells a value resolving
	 * into nothing from a value never set by this list coming back empty. An access code of an unknown kind
	 * is kept: it names no node, but it does make the value a filter, and reading it as an empty one would
	 * take every employee of the portal.
	 *
	 * @return list<string>
	 */
	public static function normalizeCodes(array $value): array
	{
		$codes = [];
		foreach ($value as $code)
		{
			if (is_string($code) && $code !== '' && !\CBPActivity::isExpression($code))
			{
				$codes[] = mb_strtolower($code);
			}
		}

		return $codes;
	}

	/**
	 * Whether the value names an employee by hand, in the `user_<id>` form of it alone. A bare id is no
	 * employee here: the core resolves such a value into a user group ({@see \CBPHelper::extractUsers()}),
	 * and the binding never expands a group into its members.
	 *
	 * @param list<string> $codes
	 */
	public static function hasUserCodes(array $codes): bool
	{
		foreach ($codes as $code)
		{
			if (self::isUserCode($code))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Codes reach here both normalised and as the template holds them, so the case is levelled first: the
	 * engine writes an access code in lower case, and a hand written one is under nobody's control.
	 */
	private static function isUserCode(string $code): bool
	{
		return preg_match(self::USER_CODE_PATTERN, mb_strtolower($code)) === 1;
	}

	/**
	 * The codes with the ones naming an employee by hand dropped: the selector cannot show such a code and
	 * the filter refuses the whole binding for it, so saving it back would save a setting nobody can either
	 * see or repair.
	 *
	 * @param list<string> $codes
	 *
	 * @return list<string>
	 */
	public static function withoutUserCodes(array $codes): array
	{
		return array_values(array_filter($codes, static fn(string $code) => !self::isUserCode($code)));
	}

	/**
	 * Nodes of the new structure the codes name, of the picked kind alone.
	 *
	 * @param list<string> $codes
	 *
	 * @return list<int>
	 */
	public static function nodeIds(array $codes, bool $withSubordinates): array
	{
		return self::idsOfCodes($codes, self::NODE_CODE_PATTERN, $withSubordinates);
	}

	/**
	 * Sections of the old structure the codes name, of the picked kind alone, as the access codes the
	 * translation into nodes of the new structure takes.
	 *
	 * @param list<string> $codes
	 *
	 * @return list<string>
	 */
	public static function sectionAccessCodes(array $codes, bool $withSubordinates): array
	{
		return AccessCodeType::IntranetDepartmentType->buildAccessCodes(
			...self::idsOfCodes($codes, self::SECTION_CODE_PATTERN, $withSubordinates),
		);
	}

	/**
	 * The identifier the selector of the dialog carries a node under.
	 */
	public static function toSelectorId(int $nodeId, bool $withSubordinates): string
	{
		return $withSubordinates ? (string)$nodeId : $nodeId . self::OWN_MEMBERS_IDENTIFIER_MARK;
	}

	/**
	 * Access codes for what the dialog sends back: identifiers of the selector, and the forms the field of
	 * expressions takes by hand: an access code of a known kind, a bare node id and an expression. A bare
	 * node id means the node together with its subordinates, exactly as it does coming from the selector.
	 * Anything else is dropped, as it is dropped today, and so is a zero id: it names no node and no section,
	 * so taking it would turn a typo into a binding nobody falls under.
	 *
	 * @return list<string>
	 */
	public static function toAccessCodes(array $values): array
	{
		$codes = [];
		foreach ($values as $value)
		{
			$code = self::toAccessCode($value);
			if ($code !== null)
			{
				$codes[] = $code;
			}
		}

		return $codes;
	}

	private static function toAccessCode(mixed $value): ?string
	{
		if (!is_string($value))
		{
			return null;
		}

		$value = trim($value);
		if ($value === '')
		{
			return null;
		}

		if (\CBPActivity::isExpression($value))
		{
			return $value;
		}

		if (preg_match(self::SELECTOR_IDENTIFIER_PATTERN, $value, $match) === 1)
		{
			$nodeId = (int)$match[1];

			return $nodeId > 0 ? self::toNodeCode($nodeId, withSubordinates: !isset($match[2])) : null;
		}

		return self::isNodeBindingCode($value) ? $value : null;
	}

	private static function toNodeCode(int $nodeId, bool $withSubordinates): string
	{
		return self::NODE_CODE_PREFIX . ($withSubordinates ? self::SUBORDINATES_CODE_MARK : '') . $nodeId;
	}

	private static function isNodeBindingCode(string $code): bool
	{
		$code = mb_strtolower($code);

		return self::namesPositiveId($code, self::NODE_CODE_PATTERN)
			|| self::namesPositiveId($code, self::SECTION_CODE_PATTERN)
		;
	}

	/**
	 * Zero is the id of no node and of no section, so a code shaped right around it names nothing at all and
	 * belongs with the codes of an unknown kind rather than with the ones naming a node.
	 */
	private static function namesPositiveId(string $code, string $pattern): bool
	{
		return preg_match($pattern, $code, $match) === 1 && (int)$match[2] > 0;
	}

	/**
	 * @param list<string> $codes
	 *
	 * @return list<int>
	 */
	private static function idsOfCodes(array $codes, string $pattern, bool $withSubordinates): array
	{
		$mark = $withSubordinates ? self::SUBORDINATES_CODE_MARK : '';

		$ids = [];
		foreach ($codes as $code)
		{
			if (preg_match($pattern, $code, $match) === 1 && $match[1] === $mark && (int)$match[2] > 0)
			{
				$ids[] = (int)$match[2];
			}
		}

		return $ids;
	}
}
