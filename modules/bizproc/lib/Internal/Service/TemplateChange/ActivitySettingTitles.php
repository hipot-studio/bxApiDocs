<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\TemplateChange;

use Bitrix\Bizproc\Internal\Service\Activity\ActivityPropertyPassports;

/**
 * The names an activity gives its own settings, the ones a person reads in the settings window.
 *
 * Where those declarations are read from is the business of {@see ActivityPropertyPassports}; what is read
 * out of them - the name of a setting - is the business of this class.
 */
final class ActivitySettingTitles
{
	/**
	 * Named parts of a block of their own, so they are not settings of it.
	 */
	private const SERVICE_PROPERTIES = ['Title', 'EditorComment'];

	/** @var array<string, array<string, string>> */
	private array $cache = [];

	public function __construct(private readonly array $documentType)
	{}

	/**
	 * @return array<string, string> title of a setting, by the key it is stored under
	 */
	public function get(string $activityType): array
	{
		return $this->cache[$activityType] ??= $this->read($activityType);
	}

	private function read(string $activityType): array
	{
		return ActivityPropertyPassports::read(
			$activityType,
			$this->documentType,
			static fn(mixed $map): array => self::readTitles($map),
		);
	}

	private static function readTitles(mixed $map): array
	{
		$titles = [];
		foreach (is_array($map) ? $map : [] as $key => $field)
		{
			$title = is_array($field) ? ($field['Name'] ?? null) : null;

			$isSetting =
				is_string($key)
				&& !in_array($key, self::SERVICE_PROPERTIES, true)
				&& is_string($title)
				&& !\CBPHelper::isEmptyValue($title)
			;
			if ($isSetting)
			{
				$titles[$key] = $title;
			}
		}

		return $titles;
	}
}
