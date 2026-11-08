<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Activity;

/**
 * The passports of the settings of an activity, read where the activity really declares them: its own
 * properties map, with the map of the settings window as the fallback. That pair, in that order, is what
 * every consumer of a passport reads - the journal of the changes of a template
 * ({@see \Bitrix\Bizproc\Internal\Service\TemplateChange\ActivitySettingTitles}), the barrier of the
 * settings of a fragment ({@see \Bitrix\Bizproc\Workflow\Template\Fragment\SettingsBarrier}) and the path of
 * the external AI agent - so the pair is declared once and here.
 *
 * What a caller takes out of a map is its own business: the journal reads the names of the settings, the
 * barrier reads their types. The order of the two maps and the moment the fallback is reached are not: a
 * consumer whose own key was missing in the first map would otherwise read the passports of another method
 * than its neighbour reads, and the two would disagree about what one and the same activity declared.
 *
 * The fallback is reached on an empty EXTRACTION and not on an empty map: a map holding nothing a passport is
 * made of declares no passport, and the map of the settings window is the honest second place to look. The
 * type of a node names the file to include, so a verdict the engine could not reach at all - a missing file,
 * a missing method, a throw inside it - is read as "nothing declared" and never as a passport of its own.
 */
final class ActivityPropertyPassports
{
	private const PROPERTIES_MAP_METHOD = 'getPropertiesMap';
	private const PROPERTIES_DIALOG_MAP_METHOD = 'getPropertiesDialogMap';

	/**
	 * @param callable(mixed): array $extract what the caller needs of a map, empty when the map holds none of it
	 */
	public static function read(string $activityType, array $documentType, callable $extract): array
	{
		$maps = [
			[self::PROPERTIES_MAP_METHOD, [$documentType, []]],
			[self::PROPERTIES_DIALOG_MAP_METHOD, []],
		];

		foreach ($maps as [$method, $arguments])
		{
			$extracted = $extract(self::callActivityStaticMethod($activityType, $method, $arguments));
			if ($extracted !== [])
			{
				return $extracted;
			}
		}

		return [];
	}

	private static function callActivityStaticMethod(string $activityType, string $method, array $arguments): mixed
	{
		try
		{
			if (!\CBPRuntime::getRuntime()->includeActivityFile($activityType))
			{
				return null;
			}

			return \CBPActivity::callStaticMethod($activityType, $method, $arguments);
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
