<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\UI\EntitySelector\SearchOptions;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Type;
use WeakMap;

class FlagOption
{
	/**
	 * @var WeakMap<SearchingFlag, bool> $flags
	 */
	private WeakMap $flags;

	private function __construct()
	{
		$this->flags = new WeakMap();
	}

	public static function getDefaultValue(SearchingFlag $flag): bool
	{
		return match ($flag)
		{
			SearchingFlag::Chats, SearchingFlag::Bots, SearchingFlag::Users => true,
		};
	}

	public static function byDefault(): self
	{
		$instance = new self();
		foreach (SearchingFlag::cases() as $flag)
		{
			$instance->flags[$flag] = self::getDefaultValue($flag);
		}

		return $instance;
	}

	/**
	 * @param Type[] $types
	 */
	public static function byChatTypes(array $types): self
	{
		$instance = self::byIncludeOnly([]);

		foreach ($types as $type)
		{
			match ($type->literal)
			{
				Chat::IM_TYPE_PRIVATE => $instance->includeMany([SearchingFlag::Bots, SearchingFlag::Users]),
				default => $instance->include(SearchingFlag::Chats),
			};
		}

		return $instance;
	}

	public static function byTypeCondition(Type\TypeCondition $typeCondition): self
	{
		$instance = self::byIncludeOnly([]);
		if (!$typeCondition->isPossible())
		{
			return $instance;
		}

		if ($typeCondition->hasAnyLiteralExcept([Chat::IM_TYPE_PRIVATE]))
		{
			$instance->include(SearchingFlag::Chats);
		}
		if ($typeCondition->hasLiteral(Chat::IM_TYPE_PRIVATE))
		{
			$instance->includeMany([SearchingFlag::Bots, SearchingFlag::Users]);
		}

		return $instance;
	}

	/**
	 * @param SearchingFlag[] $flags
	 */
	public static function byIncludeOnly(array $flags): self
	{
		$instance = new self();
		foreach (SearchingFlag::cases() as $flag)
		{
			$instance->flags[$flag] = in_array($flag, $flags, true);
		}

		return $instance;
	}

	/**
	 * @param SearchingFlag[] $flags
	 */
	public static function byExclude(array $flags): self
	{
		$instance = new self();
		foreach (SearchingFlag::cases() as $flag)
		{
			$instance->flags[$flag] = in_array($flag, $flags, true) ? false : self::getDefaultValue($flag);
		}

		return $instance;
	}

	public function include(SearchingFlag $flag): self
	{
		$this->flags[$flag] = true;
		return $this;
	}

	/**
	 * @param SearchingFlag[] $flags
	 */
	public function includeMany(array $flags): self
	{
		foreach ($flags as $flag)
		{
			$this->include($flag);
		}

		return $this;
	}

	public function exclude(SearchingFlag $flag): self
	{
		$this->flags[$flag] = false;
		return $this;
	}

	/**
	 * @param SearchingFlag[] $flags
	 */
	public function mergeWithExcludeList(array $flags): self
	{
		return $this->merge(self::byExclude($flags));
	}

	/**
	 * @param SearchingFlag[] $flags
	 */
	public function mergeWithIncludeOnlyList(array $flags): self
	{
		return $this->merge(self::byIncludeOnly($flags));
	}

	public function merge(FlagOption $option): FlagOption
	{
		foreach ($this->flags as $flag => $flagValue)
		{
			$this->flags[$flag] = $flagValue && $option->isFlagEnabled($flag);
		}

		return $this;
	}

	public function isFlagEnabled(SearchingFlag $flag): bool
	{
		return $this->flags[$flag] ?? self::getDefaultValue($flag);
	}
}
