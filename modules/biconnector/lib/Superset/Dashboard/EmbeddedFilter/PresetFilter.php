<?php

namespace Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;

use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;

abstract class PresetFilter extends UrlFilter
{
	protected ?int $value = null;

	abstract public static function getDatasetName(): string;

	/**
	 * Returns values for Superset filter.
	 *
	 * @return PresetValueCollection
	 */
	abstract public function getValues(): PresetValueCollection;

	/**
	 * Returns column name for Superset filter.
	 *
	 * @return string
	 */
	abstract public static function getColumnName(): string;

	/**
	 * Sets value for filter by default.
	 *
	 * @param int $value
	 * @return void
	 */
	public function setValue(int $value): void
	{
		$this->value = $value;
	}

	/**
	 * @inheritDoc
	 */
	public function getFormatted(): string
	{
		$urlTemplateFilter = '
			#FILTER_ID#:(
				extraFormData:(),
				filterState:(),
				id:#FILTER_ID#,
				ownState:()
			)
		';

		if ($this->value && $this->getValues()->isValueExists($this->value))
		{
			$urlTemplateFilter = '
				#FILTER_ID#:(
					extraFormData:(
						filters:!(
							(
								col:#COLUMN_NAME#,
								op:IN,
								val:!(#VALUE#)
							)
						)
					),
					filterState:(
						validateMessage:!f,
						validateStatus:!f,
						value:!(#VALUE#)
					),
					id:#FILTER_ID#,
					ownState:()
				)
			';
		}

		return strtr(
			$urlTemplateFilter,
			[
				'#FILTER_ID#' => $this->getCode(),
				'#COLUMN_NAME#' => static::getColumnName(),
				'#VALUE#' => $this->value,
			],
		);
	}

	/**
	 * Returns dataset ID for Superset filter.
	 *
	 * @return int|null
	 */
	public static function getDatasetId(): ?int
	{
		$datasetName = static::getDatasetName();

		$cacheKey = "biconnector_dataset_id_by_name_{$datasetName}";
		$cacheManager = \Bitrix\Main\Application::getInstance()->getManagedCache();

		if ($cacheManager->read(43200, $cacheKey)) //12 hours
		{
			return $cacheManager->get($cacheKey);
		}

		$integrator = Integrator::getInstance();
		$response = $integrator->getDatasetByName($datasetName);
		if ($response->hasErrors())
		{
			return null;
		}

		$datasetId = $response->getData()['id'] ?? null;
		if ($datasetId)
		{
			$cacheManager->set($cacheKey, $datasetId);
		}

		return $datasetId;
	}
}
