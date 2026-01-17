<?php

namespace Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;

use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;

abstract class PresetFilter extends UrlFilter
{
	protected ?int $value = null;

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
	abstract protected function getColumnName(): string;

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
						label:"#VALUE#",
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
				'#COLUMN_NAME#' => $this->getColumnName(),
				'#VALUE#' => $this->value,
			],
		);
	}
}
