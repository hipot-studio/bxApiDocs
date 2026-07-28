<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Main\Web\Json;
use Bitrix\MessageService\Public\UI\MessageEditor\Preferences\ChannelLastUsedFrom;
use Bitrix\MessageService\Public\UI\MessageEditor\Preferences\ChannelPosition;

final readonly class Preferences implements \JsonSerializable
{
	/**
	 * @param array<ChannelPosition> $channelsSort
	 * @param array<ChannelLastUsedFrom> $channelsLastUsedFrom
	 */
	public function __construct(
		#[Validatable(iterable: true)]
		public array $channelsSort = [],
		#[Validatable(iterable: true)]
		public array $channelsLastUsedFrom = [],
	)
	{
	}

	public static function fromArray(array $fields): self
	{
		$channelsSortItems = self::buildCollection(
			self::normalizeItems($fields['channelsSort'] ?? null),
			static fn(array $item) => ChannelPosition::fromArray($item),
		);

		$channelsLastUsedFromItems = self::buildCollection(
			self::normalizeItems($fields['channelsLastUsedFrom'] ?? null),
			static fn(array $item) => ChannelLastUsedFrom::fromArray($item),
		);

		return new self($channelsSortItems, $channelsLastUsedFromItems);
	}

	private static function normalizeItems(mixed $value): array
	{
		if (is_string($value))
		{
			try
			{
				$value = Json::decode($value);
			}
			catch (ArgumentException)
			{
				return [];
			}
		}

		return is_array($value) ? $value : [];
	}

	private static function buildCollection(array $items, callable $builder): array
	{
		$result = [];
		foreach ($items as $item)
		{
			$built = $builder(is_array($item) ? $item : []);
			if ($built !== null)
			{
				$result[] = $built;
			}
		}

		return $result;
	}

	public function jsonSerialize(): array
	{
		return [
			'channelsSort' => $this->channelsSort,
			'channelsLastUsedFrom' => $this->channelsLastUsedFrom,
		];
	}
}
