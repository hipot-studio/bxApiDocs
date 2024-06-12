<?
namespace Bitrix\Main\Dev\Converter;

use Bitrix\Main\Application;
use Bitrix\Main\Update\Stepper;
use Bitrix\Main\Config\Option;
use Bitrix\Main\UI\Filter\DateType;

class FilterOption extends Stepper
{
	protected static $moduleId = "main";
	protected $deleteFile = false;

	protected $limit = 100;

	/**
	 * The method records the necessary data for conversion into an option.
	 *
	 * @param string $filterId Filter id.
	 * @param string $tableId Grid id.
	 * @param array $ratioFields Fields of the old and new filter.
	 *   array(
	 *     "find_name" => "NAME",
	 *     "find_lang" => "LID",
	 *     ...
	 *   )
	 */
	public static function setFilterToConvert($filterId, $tableId, array $ratioFields)
	{
		$listFilter = Option::get("main", "listFilterToConvert", "");
		if ($listFilter !== "" )
			$listFilter = unserialize($listFilter);
		$listFilter = is_array($listFilter) ? $listFilter : array();
		if (!array_key_exists($filterId, $listFilter))
		{
			$listFilter[$filterId] = array(
				"offset" => 0,
				"tableId"=> $tableId,
				"ratioFields" => $ratioFields
			);
			Option::set("main", "listFilterToConvert", serialize($listFilter));
		}
	}

	public function execute(array &$option)
	{
		$listFilter = Option::get("main", "listFilterToConvert", "");
		if ($listFilter !== "" )
			$listFilter = unserialize($listFilter);
		$listFilter = is_array($listFilter) ? $listFilter : array();
		if (empty($listFilter))
		{
			Option::delete("main", array("name" => "listFilterToConvert"));
			$GLOBALS["CACHE_MANAGER"]->cleanDir("user_option");
			return false;
		}

		$connection = Application::getInstance()->getConnection();
		$sqlHelper = $connection->getSqlHelper();

		foreach ($listFilter as $filterId => $filter)
		{
			$queryObject = $connection->query("SELECT * FROM `b_user_option` WHERE `CATEGORY` = 'main.interface.grid' AND `NAME` = '".
				$sqlHelper->forSql($filter["tableId"])."' ORDER BY ID ASC LIMIT ".$this->limit." OFFSET ".$filter["offset"]);
			$selectedRowsCount = $queryObject->getSelectedRowsCount();
			while ($optionOldFilter = $queryObject->fetch())
			{
				$queryPresets = \CAdminFilter::getList(array(), array("FILTER_ID" => $filterId), true);
				$filters = array();
				while ($preset = $queryPresets->fetch())
				{
					$oldFields = unserialize($preset["FIELDS"]);
					if (is_array($oldFields))
					{
						list($newFields, $newRows) = $this->convertOldFieldsToNewFields(
							$oldFields, $filter["ratioFields"]);

						$filters["filter".$preset["ID"]."_".time()] = array(
							"name" => $preset["NAME"],
							"fields" => $newFields,
							"filter_rows" => implode(",", $newRows)
						);
					}
				}

				if (empty($filters))
					continue;

				$queryOptionCurrentFilter = $connection->query(
					"SELECT * FROM `b_user_option` WHERE 
					`CATEGORY` = 'main.ui.filter' AND 
					`USER_ID` = '".$sqlHelper->forSql($optionOldFilter["USER_ID"])."' AND 
					`NAME` = '".$sqlHelper->forSql($filter["tableId"])."' AND
					`COMMON` = '".$sqlHelper->forSql($optionOldFilter["COMMON"])."'"
				);
				if ($optionCurrentFilter = $queryOptionCurrentFilter->fetch())
				{
					$optionCurrentFilterValue = unserialize($optionCurrentFilter["VALUE"]);
					if (is_array($optionCurrentFilterValue))
					{
						$optionCurrentFilterValue["filters"] = $filters;
						$optionCurrentFilterValue["update_default_presets"] = true;
					}
					$connection->query(
						"UPDATE `b_user_option` SET 
						`VALUE` = '" . $sqlHelper->forSql(serialize($optionCurrentFilterValue)) . "' WHERE 
						`ID` = '" . $sqlHelper->forSql($optionCurrentFilter["ID"]) . "'"
					);
				}
				else
				{
					$optionNewFilter = array();
					$optionNewFilter["filters"] = $filters;
					$optionNewFilter["update_default_presets"] = true;

					$connection->query(
						"INSERT INTO `b_user_option` 
						(`ID`, `USER_ID`, `CATEGORY`, `NAME`, `VALUE`, `COMMON`) VALUES 
						(NULL, '".$sqlHelper->forSql($optionOldFilter["USER_ID"])."', 'main.ui.filter', '".
						$sqlHelper->forSql($filter["tableId"])."', '".$sqlHelper->forSql(serialize($optionNewFilter)).
						"', '".$sqlHelper->forSql($optionOldFilter["COMMON"])."')"
					);
				}
			}

			if ($selectedRowsCount < $this->limit)
			{
				unset($listFilter[$filterId]);
			}
			else
			{
				$listFilter[$filterId]["offset"] = $listFilter[$filterId]["offset"] + $selectedRowsCount;
			}

			break;
		}

		$GLOBALS["CACHE_MANAGER"]->cleanDir("user_option");

		if (!empty($listFilter))
		{
			Option::set("main", "listFilterToConvert", serialize($listFilter));
			return true;
		}
		else
		{
			Option::delete("main", array("name" => "listFilterToConvert"));
			return false;
		}
	}

	protected function convertOldFieldsToNewFields(array $oldFields, array $ratioFields)
	{
		$newFields = array();
		$newRows = array();
		foreach ($oldFields as $fieldId => $field)
		{
			if ($field["hidden"] !== "false" || (array_key_exists($fieldId, $ratioFields) &&
					array_key_exists($ratioFields[$fieldId], $newFields)))
				continue;

			if (preg_match("/_FILTER_PERIOD/", $fieldId, $matches,  PREG_OFFSET_CAPTURE))
			{
				$searchResult = current($matches);
				$oldDateType = $field["value"];
				$dateFieldId = substr($fieldId, 0, $searchResult[1]);
				$dateValue = array_key_exists($dateFieldId."_FILTER_DIRECTION", $oldFields) ?
					$oldFields[$dateFieldId."_FILTER_DIRECTION"]["value"] : "";
				$newDateType = $this->getNewDateType($oldDateType, $dateValue);
				if (substr($dateFieldId, -2) == "_1")
					$fieldId = substr($dateFieldId, 0, strlen($dateFieldId)-2);
				else
					$fieldId = $dateFieldId;
				$from = "";
				$to = "";
				if ($newDateType == DateType::EXACT || $newDateType == DateType::RANGE)
				{
					$from = $oldFields[$fieldId."_1"]["value"];
					$to = $oldFields[$fieldId."_2"]["value"];
				}
				$newFields[$ratioFields[$fieldId]."_datesel"] = $newDateType;
				$newFields[$ratioFields[$fieldId]."_from"] = $from;
				$newFields[$ratioFields[$fieldId]."_to"] = $to;
				$newFields[$ratioFields[$fieldId]."_days"] = "";
				$newFields[$ratioFields[$fieldId]."_month"] = "";
				$newFields[$ratioFields[$fieldId]."_quarter"] = "";
				$newFields[$ratioFields[$fieldId]."_year"] = "";
				$newRows[] = $ratioFields[$fieldId];
			}
			elseif (substr($fieldId, -2) === "_1")
			{
				$fieldId = substr($fieldId, 0, strlen($fieldId)-2);
				if (array_key_exists($fieldId, $ratioFields) && array_key_exists($fieldId."_2", $oldFields) &&
					!array_key_exists($fieldId."_FILTER_PERIOD", $oldFields))
				{
					$newFields[$ratioFields[$fieldId]."_numsel"] = "range";
					$newFields[$ratioFields[$fieldId]."_from"] = $field["value"];
					$newFields[$ratioFields[$fieldId]."_to"] = $oldFields[$fieldId."_2"]["value"];
					$newRows[] = $ratioFields[$fieldId];
				}
			}
			elseif (array_key_exists($fieldId, $ratioFields))
			{
				$newFields[$ratioFields[$fieldId]] = $field["value"];
				$newRows[] = $ratioFields[$fieldId];
			}
		}

		return array($newFields, $newRows);
	}

	protected function getNewDateType($oldDateType, $oldDateValue)
	{
		$newDateType = DateType::EXACT;

		switch ($oldDateType)
		{
			case "day":
				switch ($oldDateValue)
				{
					case "previous":
						$newDateType = DateType::YESTERDAY;
						break;
					case "current":
						$newDateType = DateType::CURRENT_DAY;
						break;
					case "next":
						$newDateType = DateType::TOMORROW;
						break;
				}
				break;
			case "week":
				switch ($oldDateValue)
				{
					case "previous":
						$newDateType = DateType::LAST_WEEK;
						break;
					case "current":
						$newDateType = DateType::CURRENT_WEEK;
						break;
					case "next":
						$newDateType = DateType::NEXT_WEEK;
						break;
				}
				break;
			case "month":
				switch ($oldDateValue)
				{
					case "previous":
						$newDateType = DateType::LAST_MONTH;
						break;
					case "current":
						$newDateType = DateType::CURRENT_MONTH;
						break;
					case "next":
						$newDateType = DateType::NEXT_MONTH;
						break;
				}
				break;
			case "quarter":
				switch ($oldDateValue)
				{
					case "current":
						$newDateType = DateType::CURRENT_QUARTER;
						break;
					case "previous":
					case "next":
						$newDateType = DateType::QUARTER;
						break;
				}
				break;
			case "year":
				$newDateType = DateType::YEAR;
				break;
			case "exact":
				$newDateType = DateType::EXACT;
				break;
			case "before":
			case "after":
			case "interval":
				$newDateType = DateType::RANGE;
				break;
		}

		return $newDateType;
	}
}