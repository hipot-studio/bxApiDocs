<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * Deterministic column aliases of the protocol (design §2.5).
 *
 * The alias is byte-deterministic on both sides: the portal recomputes every alias it receives and
 * rejects the request when one differs, so an alias can be trusted as a reference in HAVING and
 * ORDER BY.
 */
final class AliasGenerator
{
	public const PATTERN = '/^[ga][0-9]+_[a-z0-9_]*_[0-9a-f]{8}$/';

	private const HASH_LENGTH = 8;
	private const SLUG_MAX_BYTES = 24;

	/** Placeholder for the missing argument of count(*). */
	public const STAR = '*';

	private function __construct()
	{
	}

	public static function forGroupKey(
		int $position,
		string $relation,
		string $field,
		?DateTruncSpec $transform,
	): string
	{
		$parts = $transform === null
			? ['K', $relation, $field, '-']
			: [
				'K',
				$relation,
				$field,
				DateTruncSpec::KIND,
				$transform->unit,
				(string)$transform->bucket,
				(string)$transform->preOffsetDays,
				(string)$transform->postOffsetDays,
			]
		;

		return self::build('g', $position, $parts, $field);
	}

	public static function forAggregate(
		int $position,
		string $relation,
		?string $field,
		string $function,
		bool $distinct,
	): string
	{
		$parts = ['A', $relation, $field ?? self::STAR, $function, $distinct ? '1' : '0'];

		return self::build('a', $position, $parts, $field ?? '');
	}

	/**
	 * @param string[] $parts
	 */
	private static function build(string $prefix, int $position, array $parts, string $slugSource): string
	{
		$descriptor = '';
		foreach ($parts as $part)
		{
			$descriptor .= strlen($part) . ':' . $part . ';';
		}

		$hash = substr(hash('sha256', $descriptor), 0, self::HASH_LENGTH);

		return $prefix . $position . '_' . self::slug($slugSource) . '_' . $hash;
	}

	/**
	 * ASCII letters, digits and underscores only, lowercased locale-independently and truncated to
	 * 24 bytes. May legitimately end up empty, which yields two underscores in a row.
	 */
	private static function slug(string $source): string
	{
		$slug = preg_replace('/[^A-Za-z0-9_]/', '', $source) ?? '';
		$slug = strtr($slug, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');

		return substr($slug, 0, self::SLUG_MAX_BYTES);
	}
}
