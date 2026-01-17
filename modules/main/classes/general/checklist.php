<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2025 Bitrix
 */

use Bitrix\Main\Localization\Translation;
use Bitrix\Main\Text\Encoding;

IncludeModuleLangFile(__FILE__);

class CCheckList
{
	public $current_result = false;
	public $started = false;
	public $checklist;
	protected $report_id = false;
	protected $report_info = "";

	public function __construct($ID = false)
	{
		$checklist_path = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/checklist_structure.php";
		if (file_exists($checklist_path))
		{
			$arCheckList = include $checklist_path;
		}
		else
		{
			return;
		}

		//bind custom checklist
		foreach (GetModuleEvents('main', 'OnCheckListGet', true) as $arEvent)
		{
			$arCustomChecklist = ExecuteModuleEventEx($arEvent, [$arCheckList]);

			if (is_array($arCustomChecklist["CATEGORIES"]))
			{
				foreach ($arCustomChecklist["CATEGORIES"] as $section_id => $arSectionFields)
				{
					if (!$arCheckList["CATEGORIES"][$section_id])
					{
						$arCheckList["CATEGORIES"][$section_id] = $arSectionFields;
					}
				}
			}
			if (is_array($arCustomChecklist["POINTS"]))
			{
				foreach ($arCustomChecklist["POINTS"] as $point_id => $arPointFields)
				{
					$parent = $arCustomChecklist["POINTS"][$point_id]["PARENT"];
					if (!$arCheckList["POINTS"][$point_id] && array_key_exists($parent, $arCheckList["CATEGORIES"]))
					{
						$arCheckList["POINTS"][$point_id] = $arPointFields;
					}
				}
			}
		}

		$this->checklist = $arCheckList;
		$arFilter["REPORT"] = "N";
		if (intval($ID) > 0)
		{
			$arFilter["ID"] = $ID;
			$arFilter["REPORT"] = "Y";
		}

		$dbResult = CCheckListResult::GetList([], $arFilter);
		if ($arCurrentResult = $dbResult->Fetch())
		{
			$this->current_result = unserialize($arCurrentResult["STATE"], ['allowed_classes' => false]);
			if (intval($ID) > 0)
			{
				$this->report_id = intval($ID);
				unset($arCurrentResult["STATE"]);
				$this->report_info = $arCurrentResult;
			}

			foreach ($arCheckList["POINTS"] as $key => $arFields)
			{
				if (empty($this->current_result[$key]))
				{
					if ($this->report_id)
					{
						unset($this->checklist["POINTS"][$key]);
					}
					else
					{
						$this->current_result[$key] = [
							"STATUS" => "W"];
					}
				}
			}
		}
		if ($this->current_result && !$this->report_id)
		{
			$this->started = true;
		}
	}

	public function GetSections()
	{
		$arSections = $this->checklist["CATEGORIES"];
		$arResult = [];
		foreach ($arSections as $key => $arFields)
		{
			$arResult[$key] = array_merge($this->GetDescription($key), $arFields);
			$arResult[$key]["STATS"] = $this->GetSectionStat($key);
		}
		return $arResult;
	}

	//getting sections statistic
	public function GetSectionStat($ID = false)
	{
		$arResult = [
			"CHECK" => 0,
			"CHECK_R" => 0,
			"FAILED" => 0,
			"WAITING" => 0,
			"TOTAL" => 0,
			"REQUIRE_CHECK" => 0,
			"REQUIRE_SKIP" => 0,
			"NOT_REQUIRE_CHECK" => 0,
			"NOT_REQUIRE_SKIP" => 0,
			"CHECKED" => "N",
			"REQUIRE" => 0,
		];

		if (($ID && array_key_exists($ID, $this->checklist["CATEGORIES"])) || !$ID)
		{
			$arPoints = $this->GetPoints($ID);
			$arSections = $this->checklist["CATEGORIES"];
			if (!empty($arPoints))
			{
				foreach ($arPoints as $arPointFields)
				{
					if ($arPointFields["STATE"]["STATUS"] == "A")
					{
						$arResult["CHECK"]++;
						if (isset($arPointFields['REQUIRE']) && $arPointFields['REQUIRE'] == 'Y')
						{
							$arResult["CHECK_R"]++;
						}
					}
					if ($arPointFields["STATE"]["STATUS"] == "F")
					{
						$arResult["FAILED"]++;
					}
					if ($arPointFields["STATE"]["STATUS"] == "W")
					{
						$arResult["WAITING"]++;
					}
					if (isset($arPointFields["REQUIRE"]) && $arPointFields["REQUIRE"] == "Y")
					{
						$arResult["REQUIRE"]++;
						if ($arPointFields["STATE"]["STATUS"] == "A")
						{
							$arResult["REQUIRE_CHECK"]++;
						}
						elseif ($arPointFields["STATE"]["STATUS"] == "S")
						{
							$arResult["REQUIRE_SKIP"]++;
						}
					}
					else
					{
						if ($arPointFields["STATE"]["STATUS"] == "A")
						{
							$arResult["NOT_REQUIRE_CHECK"]++;
						}
						elseif ($arPointFields["STATE"]["STATUS"] == "S")
						{
							$arResult["NOT_REQUIRE_SKIP"]++;
						}
					}
				}
			}
			$arResult["TOTAL"] = count($arPoints);

			if ($ID)
			{
				foreach ($arSections as $key => $arFields)
				{
					if (isset($arFields["PARENT"]) && $arFields["PARENT"] == $ID)
					{
						$arSubSectionStat = $this->GetSectionStat($key);
						$arResult["TOTAL"] += $arSubSectionStat["TOTAL"];
						$arResult["CHECK"] += $arSubSectionStat["CHECK"];
						$arResult["FAILED"] += $arSubSectionStat["FAILED"];
						$arResult["WAITING"] += $arSubSectionStat["WAITING"];
						$arResult["REQUIRE"] += $arSubSectionStat["REQUIRE"];
						$arResult["REQUIRE_CHECK"] += $arSubSectionStat["REQUIRE_CHECK"];
						$arResult["REQUIRE_SKIP"] += $arSubSectionStat["REQUIRE_SKIP"];
					}
				}
			}
			if (
				($arResult["REQUIRE"] > 0 && $arResult["FAILED"] == 0 && $arResult["REQUIRE"] == $arResult["REQUIRE_CHECK"])
				|| ($arResult["REQUIRE"] == 0 && $arResult["FAILED"] == 0 && $arResult["TOTAL"] > 0)
				|| ($arResult["CHECK"] == $arResult["TOTAL"] && $arResult["TOTAL"] > 0)
			)
			{
				$arResult["CHECKED"] = "Y";
			}
		}

		return $arResult;
	}

	public function GetPoints($arSectionCode = false)
	{
		$arCheckList = $this->GetCurrentState();
		$arResult = [];
		if (is_array($arCheckList) && !empty($arCheckList))
		{
			foreach ($arCheckList["POINTS"] as $key => $arFields)
			{
				$arFields = array_merge($this->GetDescription($key), $arFields);

				if ($arFields["PARENT"] == $arSectionCode || !$arSectionCode)
				{
					$arResult[$key] = $arFields;
				}
				if (isset($arResult[$key]["STATE"]['COMMENTS']) && is_array($arResult[$key]["STATE"]['COMMENTS']))
				{
					$arResult[$key]["STATE"]['COMMENTS_COUNT'] = count($arResult[$key]["STATE"]['COMMENTS']);
				}
			}
		}

		return $arResult;
	}

	public function GetStructure()
	{
		//build checklist structure with section statistic & status info
		$arSections = $this->GetSections();
		foreach ($arSections as $key => $arSectionFields)
		{
			if (empty($arSectionFields["CATEGORIES"]))
			{
				$arSections[$key]["CATEGORIES"] = [];
				$arSectionFields["CATEGORIES"] = [];
			}
			if (empty($arSectionFields["PARENT"]))
			{
				$arSections[$key]["POINTS"] = $this->GetPoints($key);
				$arSections[$key] = array_merge($arSections[$key], $this->GetSectionStat($key));
				continue;
			}

			$arFields = $arSectionFields;
			$arFields["POINTS"] = $this->GetPoints($key);
			$arFields = array_merge($arFields, $this->GetSectionStat($key));
			$arSections[$arFields["PARENT"]]["CATEGORIES"][$key] = $arFields;
			unset($arSections[$key]);
		}

		$arResult["STRUCTURE"] = $arSections;
		$arResult["STAT"] = $this->GetSectionStat();
		return $arResult;
	}

	public function PointUpdate($arTestID, $arPointFields = [])
	{
		//update test info in the object property
		if (!$arTestID || empty($arPointFields) || $this->report_id)
		{
			return false;
		}

		$currentFields =
			is_array($this->current_result) && isset($this->current_result[$arTestID])
				? $this->current_result[$arTestID]
				: [];

		if (empty($arPointFields["STATUS"]))
		{
			$arPointFields["STATUS"] = $currentFields["STATUS"] ?? [];
		}

		$this->current_result[$arTestID] = $arPointFields;

		return true;
	}

	protected function GetDescription($ID)
	{
		//getting description of sections and points
		$file = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/lang/" . LANG . "/admin/checklist/" . $ID . ".html";
		$howTo = "";
		if (file_exists($file))
		{
			$howTo = file_get_contents($file);
		}

		$convertEncoding = Translation::needConvertEncoding(LANG);
		if ($convertEncoding)
		{
			$targetEncoding = Translation::getCurrentEncoding();
			$sourceEncoding = Translation::getSourceEncoding(LANG);
			if ($targetEncoding !== 'utf-8' || !preg_match('//u', $howTo))
			{
				$howTo = Encoding::convertEncoding($howTo, $sourceEncoding, $targetEncoding);
			}
		}

		return [
			"NAME" => GetMessage("CL_" . $ID),
			"DESC" => GetMessage("CL_" . $ID . "_DESC", ['#LANG#' => LANG]),
			"AUTOTEST_DESC" => GetMessage("CL_" . $ID . "_AUTOTEST_DESC"),
			"HOWTO" => ($howTo <> '') ? (str_ireplace('#LANG#', LANG, $howTo)) : "",
			"LINKS" => GetMessage("CL_" . $ID . "_LINKS"),
		];
	}

	public function Save()
	{
		//saving current state
		if (!$this->report_id)
		{
			$res = CCheckListResult::Save(["STATE" => $this->current_result]);
			if (!is_array($res))
			{
				CUserOptions::SetOption("checklist", "autotest_start", "Y", true);
			}
			return $res;
		}
		return false;
	}

	protected function GetCurrentState()
	{
		//getting current state
		$arCheckList = $this->checklist;
		$currentState = $this->current_result;
		foreach ($arCheckList["POINTS"] as $testID => $arTestFields)
		{
			if (!empty($currentState[$testID]))
			{
				$arCheckList["POINTS"][$testID]["STATE"] = $currentState[$testID];
			}
			else
			{
				$arCheckList["POINTS"][$testID]["STATE"] = [
					"STATUS" => "W",
				];
			}
		}

		return $arCheckList;
	}

	public function AutoCheck($testId, $arParams = [])
	{
		//execute point autotest
		$arParams["TEST_ID"] = $testId;
		$arPoints = $this->GetPoints();
		$arPoint = $arPoints[$testId];

		if (!$arPoint || $arPoint["AUTO"] != "Y")
		{
			return false;
		}

		if (isset($arPoint["PARAMS"]) && is_array($arPoint["PARAMS"]))
		{
			$arParams = array_merge($arParams, $arPoint["PARAMS"]);
		}
		if (isset($arPoint["STATE"]["PARAMS"]) && is_array($arPoint["STATE"]["PARAMS"]))
		{
			$arParams = array_merge($arParams, $arPoint["STATE"]["PARAMS"]);
		}

		$arClass = $arPoint["CLASS_NAME"];
		$arMethod = $arPoint["METHOD_NAME"];

		if (!empty($arPoint["FILE_PATH"]) && file_exists($_SERVER["DOCUMENT_ROOT"] . $arPoint["FILE_PATH"]))
		{
			include($_SERVER["DOCUMENT_ROOT"] . $arPoint["FILE_PATH"]);
		}

		$result = false;
		if (is_callable([$arClass, $arMethod]))
		{
			$result = call_user_func_array([$arClass, $arMethod], [$arParams]);
		}

		$arResult = [];
		if ($result && is_array($result))
		{
			if (array_key_exists("STATUS", $result))
			{
				$arFields["STATUS"] = "F";
				if ($result['STATUS'] == "true")
				{
					$arFields["STATUS"] = "A";
				}

				$arFields["COMMENTS"] = $arPoint["STATE"]["COMMENTS"] ?? [];
				$arFields["COMMENTS"]["SYSTEM"] = [];
				if (isset($result["MESSAGE"]["PREVIEW"]))
				{
					$arFields["COMMENTS"]["SYSTEM"]["PREVIEW"] = $result["MESSAGE"]["PREVIEW"];
				}
				if (isset($result["MESSAGE"]["DETAIL"]))
				{
					$arFields["COMMENTS"]["SYSTEM"]["DETAIL"] = $result["MESSAGE"]["DETAIL"];
				}

				if ($this->PointUpdate($testId, $arFields))
				{
					if ($this->Save())
					{
						$arResult = [
							"STATUS" => $arFields["STATUS"],
							"COMMENTS_COUNT" => count($arFields["COMMENTS"] ?? []),
							"ERROR" => $result["ERROR"] ?? null,
							"SYSTEM_MESSAGE" => $arFields["COMMENTS"]["SYSTEM"] ?? '',
						];
					}
				}
			}
			elseif ($result["IN_PROGRESS"] == "Y")
			{
				$arResult = [
					"IN_PROGRESS" => "Y",
					"PERCENT" => $result["PERCENT"],
				];

				if (isset($result["PARAMS"]))
				{
					$this->PointUpdate($testId, ["PARAMS" => $result["PARAMS"]]);
					$this->Save();
				}
			}
		}
		else
		{
			$arResult = ["STATUS" => "W"];
		}

		return $arResult;
	}

	public function AddReport($arReportFields = [], $errorCheck = false)
	{
		//saving current state to a report
		if ($this->report_id)
		{
			return false;
		}

		if ($errorCheck && !$arReportFields["TESTER"] && !$arReportFields["COMPANY_NAME"])
		{
			return ["ERROR" => GetMessage("EMPTY_NAME")];
		}

		$arStats = $this->GetSectionStat();
		$arFields = [
			"TESTER" => $arReportFields["TESTER"],
			"COMPANY_NAME" => $arReportFields["COMPANY_NAME"],
			"PHONE" => $arReportFields["PHONE"],
			"EMAIL" => $arReportFields["EMAIL"],
			"PICTURE" => $arReportFields["PICTURE"],
			"REPORT_COMMENT" => $arReportFields["COMMENT"],
			"STATE" => $this->current_result,
			"TOTAL" => $arStats["TOTAL"],
			"SUCCESS" => $arStats["CHECK"],
			"SUCCESS_R" => $arStats["CHECK_R"],
			"FAILED" => $arStats["FAILED"],
			"PENDING" => $arStats["WAITING"],
			"REPORT" => true,
		];

		$arReportID = CCheckListResult::Save($arFields);
		if ($arReportID > 0)
		{
			$dbres = CCheckListResult::GetList([], ["REPORT" => "N"]);
			if ($res = $dbres->Fetch())
			{
				CCheckListResult::Delete($res["ID"]);
				CUserOptions::SetOption("checklist", "autotest_start", "N", true);
			}
			return $arReportID;
		}

		return false;
	}

	public function GetReportInfo()
	{
		//getting report information
		if ($this->report_id && $this->current_result)
		{
			$arResult = $this->GetStructure();

			$arResult["POINTS"] = $this->GetPoints();
			$arResult["INFO"] = $this->report_info;

			return $arResult;
		}
		return false;
	}
}
