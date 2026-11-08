<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Layout;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class AccessCode
{
	private const MAX_VALUE_LENGTH = 10;

	private const PREFIX_BY_TYPE = [
		'user' => 'U',
		'department' => 'DR',
		'project' => 'SG',
		'structure-node' => 'SNDR',
	];

	private const TYPE_BY_PREFIX = [
		'U' => 'user',
		'DR' => 'department',
		'SG' => 'project',
		'SNDR' => 'structure-node',
	];

	private function __construct(
		private readonly string $type,
		private readonly int $id,
	)
	{
	}

	/**
	 * Creates an audience access code.
	 */
	public static function create(string $type, string|int $id): Result
	{
		if (!isset(self::PREFIX_BY_TYPE[$type]))
		{
			return self::invalidResult();
		}

		if (
			is_string($id)
			&& (
				preg_match('/^[0-9]+$/D', $id) !== 1
				|| !self::isInIntRange(ltrim($id, '0'))
			)
		)
		{
			return self::invalidResult();
		}

		$id = (int)$id;
		if (
			$id <= 0
			|| strlen(self::PREFIX_BY_TYPE[$type] . $id) > self::MAX_VALUE_LENGTH
		)
		{
			return self::invalidResult();
		}

		return (new Result())->setData([
			'accessCode' => new self($type, $id),
		]);
	}

	/**
	 * Restores an audience access code from its canonical persisted value.
	 */
	public static function fromValue(string $value): Result
	{
		if (preg_match('/^(SNDR|DR|SG|U)([1-9][0-9]*)$/D', $value, $matches) !== 1)
		{
			return self::invalidResult();
		}

		return self::create(self::TYPE_BY_PREFIX[$matches[1]], $matches[2]);
	}

	/**
	 * Returns the access-code family.
	 */
	public function getType(): string
	{
		return $this->type;
	}

	/**
	 * Returns the positive audience member identifier.
	 */
	public function getId(): int
	{
		return $this->id;
	}

	/**
	 * Returns the canonical persisted access code.
	 */
	public function getValue(): string
	{
		return self::PREFIX_BY_TYPE[$this->type] . $this->id;
	}

	/**
	 * Returns the normalized input accepted by the internal layout service.
	 *
	 * @return array{type: string, id: int}
	 */
	public function toArray(): array
	{
		return [
			'type' => $this->type,
			'id' => $this->id,
		];
	}

	private static function invalidResult(): Result
	{
		return (new Result())->addError(
			new Error('The layout audience access code is invalid.', 'INVALID_LAYOUT_AUDIENCE'),
		);
	}

	private static function isInIntRange(string $value): bool
	{
		$max = (string)PHP_INT_MAX;

		return strlen($value) < strlen($max)
			|| (strlen($value) === strlen($max) && strcmp($value, $max) <= 0);
	}
}
