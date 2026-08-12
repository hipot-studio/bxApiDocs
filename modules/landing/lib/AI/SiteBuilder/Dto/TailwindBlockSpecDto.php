<?php
namespace Bitrix\Landing\AI\SiteBuilder\Dto;

final class TailwindBlockSpecDto extends AbstractDto
{
	private const REQUIRED_KEYS = [
		'context',
		'navigation_title',
		'goal',
		'outline',
		'content',
		'style',
		'palette',
		'images_count',
	];
	private const REQUIRED_STRING_KEYS = [
		'context',
		'navigation_title',
		'goal',
		'outline',
		'content',
		'style',
		'palette',
	];

	private string $context;
	private string $navigationTitle;
	private string $goal;
	private string $outline;
	private string $content;
	private string $style;
	private string $palette;
	private int $imagesCount;

	public function __construct(
		string $context,
		string $navigationTitle,
		string $goal,
		string $outline,
		string $content,
		string $style,
		string $palette,
		int $imagesCount
	)
	{
		$this->context = trim($context);
		$this->navigationTitle = trim($navigationTitle);
		$this->goal = trim($goal);
		$this->outline = trim($outline);
		$this->content = trim($content);
		$this->style = trim($style);
		$this->palette = trim($palette);
		$this->imagesCount = $imagesCount;
	}

	public static function getRequiredKeys(): array
	{
		return self::REQUIRED_KEYS;
	}

	public static function hasExactKeys(array $source): bool
	{
		$itemKeys = array_keys($source);
		sort($itemKeys);

		$expectedKeys = self::REQUIRED_KEYS;
		sort($expectedKeys);

		return $itemKeys === $expectedKeys;
	}

	public static function fromArray(array $source): ?self
	{
		$normalized = [];
		foreach (self::REQUIRED_STRING_KEYS as $key)
		{
			$value = trim((string)($source[$key] ?? ''));
			if ($value === '')
			{
				return null;
			}

			$normalized[$key] = $value;
		}
		$imagesCount = $source['images_count'] ?? null;
		if (
			!is_int($imagesCount)
			&& !(is_string($imagesCount) && preg_match('/^\d+$/', $imagesCount))
		)
		{
			return null;
		}
		$imagesCount = (int)$imagesCount;

		return new self(
			$normalized['context'],
			$normalized['navigation_title'],
			$normalized['goal'],
			$normalized['outline'],
			$normalized['content'],
			$normalized['style'],
			$normalized['palette'],
			$imagesCount
		);
	}

	public function toArray(): array
	{
		return [
			'context' => $this->context,
			'navigation_title' => $this->navigationTitle,
			'goal' => $this->goal,
			'outline' => $this->outline,
			'content' => $this->content,
			'style' => $this->style,
			'palette' => $this->palette,
			'images_count' => $this->imagesCount,
		];
	}

	public function toJson(int $flags = 0): ?string
	{
		$json = json_encode($this->toArray(), $flags);
		if (!is_string($json) || $json === '' || $json === 'null')
		{
			return null;
		}

		return $json;
	}
}
