<?php

namespace Bitrix\BIConnector\Internal\Services\Dataset;

use Bitrix\BIConnector\Integration\Superset\CultureFormatter;

/**
 * Resolves a dataset technical table name into a human-readable label (verbose name).
 *
 * The label is the dataset short-name phrase in the dashboard language, or null when there is no
 * such phrase; what counts as "no phrase" is owned by {@see TableDescriptionProvider}.
 */
final class VerboseNameResolver
{
	private const MAX_LENGTH = 1024;

	private TableDescriptionProvider $provider;

	public function __construct(?TableDescriptionProvider $provider = null)
	{
		$this->provider = $provider ?? new TableDescriptionProvider();
	}

	/**
	 * Technical table name -> verbose label, or null when no short-name phrase exists.
	 */
	public function resolveVerboseName(string $tableName): ?string
	{
		$dashboardLang = CultureFormatter::getLanguageCode();

		$phrase = $this->provider->getLabel($tableName, $dashboardLang);
		if ($phrase === null || trim($phrase) === '')
		{
			return null;
		}

		$label = trim($phrase);
		if (mb_strlen($label) > self::MAX_LENGTH)
		{
			$label = mb_substr($label, 0, self::MAX_LENGTH);
		}

		return $label;
	}
}
