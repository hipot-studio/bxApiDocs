<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Type;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Type;

class TypeCondition
{
	/** @var Type[]|null */
	public readonly ?array $include;
	/** @var Type[] */
	public readonly array $exclude;

	/**
	 * @param Type[]|null $include null = no include filter, [] = nothing matches
	 * @param Type[] $exclude
	 */
	public function __construct(?array $include = null, array $exclude = [])
	{
		$this->exclude = self::deduplicate($exclude);
		$this->include = $include !== null ? self::subtract(self::deduplicate($include), $this->exclude) : null;
	}

	public function merge(self $other): static
	{
		$mergedInclude = match (true)
		{
			$this->include === null && $other->include === null => null,
			$this->include === null => $other->include,
			$other->include === null => $this->include,
			default => array_merge($this->include, $other->include),
		};

		return new static(
			include: $mergedInclude,
			exclude: array_merge($this->exclude, $other->exclude),
		);
	}

	public function hasLiteral(string $literal): bool
	{
		foreach ($this->exclude as $type)
		{
			if ($type->literal === $literal && $type->entityType === null)
			{
				return false;
			}
		}

		if ($this->include !== null)
		{
			foreach ($this->include as $type)
			{
				if ($type->literal === $literal)
				{
					return true;
				}
			}

			return false;
		}

		return true;
	}

	/**
	 * @param string[] $ignoredLiterals
	 */
	public function hasAnyLiteralExcept(array $ignoredLiterals): bool
	{
		$requiredLiterals = array_diff(Chat::IM_TYPES, $ignoredLiterals);
		if ($this->include !== null)
		{
			foreach ($this->include as $type)
			{
				if (in_array($type->literal, $requiredLiterals, true))
				{
					return true;
				}
			}

			return false;
		}

		$excludedLiterals = [];
		foreach ($this->exclude as $type)
		{
			if ($type->entityType === null)
			{
				$excludedLiterals[] = $type->literal;
			}
		}

		return !empty(array_diff($requiredLiterals, $excludedLiterals));
	}

	public function hasConditions(): bool
	{
		return $this->include !== null || !empty($this->exclude);
	}

	public function isPossible(): bool
	{
		return $this->include === null || !empty($this->include);
	}

	/**
	 * @param Type[] $types
	 * @return Type[]
	 */
	private static function deduplicate(array $types): array
	{
		$result = [];
		foreach ($types as $type)
		{
			$result[$type->getFilterKey()] = $type;
		}

		return array_values($result);
	}

	/**
	 * @param Type[] $include
	 * @param Type[] $exclude
	 * @return Type[]
	 */
	private static function subtract(array $include, array $exclude): array
	{
		if (empty($exclude))
		{
			return $include;
		}

		$excludeKeys = [];
		foreach ($exclude as $type)
		{
			$excludeKeys[$type->getFilterKey()] = true;
		}

		return array_values(array_filter(
			$include,
			static fn(Type $type) => !isset($excludeKeys[$type->getFilterKey()]),
		));
	}
}
