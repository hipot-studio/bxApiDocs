<?php

namespace Bitrix\BIConnector\Internal\Services\Dataset;

use Bitrix\BIConnector\Manager;

/**
 * Source of the raw dataset short-name phrase for {@see VerboseNameResolver}.
 *
 * Reads the unified BI data-source catalog — the Superset service table list — in the dashboard
 * language and answers, per technical table name, the human label or null.
 *
 * A label exists only when the catalog `TABLE_DESCRIPTION` is a non-empty string different from the
 * technical table name: every data source bakes that name in as the default description when no lang
 * phrase exists, so the phrase and its absence are otherwise indistinguishable. By deliberate
 * trade-off a genuine label that literally equals the technical name is dropped too.
 */
class TableDescriptionProvider
{
	/**
	 * @var array<string, array<string, mixed>>
	 */
	private array $descriptionsByLang = [];

	/**
	 * Human label of the table, or null when the catalog carries only the technical-name fallback.
	 */
	public function getLabel(string $tableName, string $dashboardLang): ?string
	{
		$description = $this->getDescriptions($dashboardLang)[$tableName] ?? null;
		if (!is_string($description) || $description === '' || $description === $tableName)
		{
			return null;
		}

		return $description;
	}

	/**
	 * Catalog read, separated so a test double can answer without a live catalog.
	 *
	 * @return array<string, mixed>
	 */
	protected function loadCatalog(string $dashboardLang): array
	{
		$service = Manager::getInstance()->createService('superset');
		if ($service === null)
		{
			return [];
		}

		$service->setLanguage($dashboardLang);

		$descriptions = [];
		foreach ($service->getTableList() as [$tableName, $description])
		{
			$descriptions[$tableName] = $description;
		}

		return $descriptions;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getDescriptions(string $dashboardLang): array
	{
		return $this->descriptionsByLang[$dashboardLang] ??= $this->loadCatalog($dashboardLang);
	}
}
