<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Analytics;

use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Entity\ValueObjectInterface;

class AnalyticsData implements ValueObjectInterface
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?Event $event = null,
		public readonly ?Category $category = null,
		public readonly ?Section $section = null,
		public readonly ?SubSection $subSection = null,
		public readonly ?Element $element = null,
		public readonly ?array $parameters = null,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'event' => $this->event?->value,
			'category' => $this->category?->value,
			'section' => $this->section?->value,
			'subSection' => $this->subSection?->value,
			'element' => $this->element?->value,
			'parameters' => $this->parameters,
		];
	}

	public static function mapFromAnalyticsEvent(AnalyticsEvent $analyticsEvent): static
	{
		$data = $analyticsEvent->exportToArray();

		$parameters = [];

		for ($parameterIndex = 1; $parameterIndex <= 5; $parameterIndex++)
		{
			$key = 'p' . $parameterIndex;

			if (isset($data[$key]))
			{
				$parameters[$key] = $data[$key];
			}
		}

		if (!empty($parameters))
		{
			$data['parameters'] = $parameters;
		}

		return static::mapFromArray($data);
	}

	public static function mapFromArray(array $props): static
	{
		return new self(
			event: static::mapBackedEnum($props, 'event', Event::class),
			category: static::mapBackedEnum($props, 'category', Category::class) ?? Category::TaskOperations,
			section: static::mapBackedEnum($props, 'section', Section::class),
			subSection: static::mapBackedEnum($props, 'subSection', SubSection::class),
			element: static::mapBackedEnum($props, 'element', Element::class),
			parameters: static::mapArray($props, 'parameters'),
		);
	}
}
