<?php

namespace Bitrix\BIConnector\Internal\Grid;

use Bitrix\Main\UI\Filter\Options;

final class FilterPresetSynchronizer
{
	public static function sync(string $filterId, array $presets): void
	{
		global $USER;

		if ($presets === [] || !($USER instanceof \CUser) || !$USER->isAuthorized())
		{
			return;
		}

		$options = new Options($filterId, $presets);
		$savedOptions = $options->fetchOptions($filterId);
		$savedPresets = is_array($savedOptions['filters'] ?? null) ? $savedOptions['filters'] : [];

		$isChanged = false;
		foreach ($presets as $presetId => $preset)
		{
			if (isset($savedPresets[$presetId]) || $options->isDeletedPreset($presetId))
			{
				continue;
			}

			$options->setFilterSettings($presetId, $preset, false, false);
			$isChanged = true;
		}

		if ($isChanged)
		{
			$options->save();
		}
	}
}
