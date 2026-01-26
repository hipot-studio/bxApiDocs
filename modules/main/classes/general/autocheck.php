<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2025 Bitrix
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Service\Version\BitrixVm;
use Bitrix\Main\UpdateSystem\Checksum;
use Bitrix\Main\Application;
use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

IncludeModuleLangFile(__FILE__);

class CAutoCheck
{
	public static function CheckCustomComponents($arParams)
	{
		$arResult["STATUS"] = false;
		$arComponentFolders = [
			"/bitrix/components",
			"/local/components",
		];
		$components = [];
		foreach ($arComponentFolders as $componentFolder)
		{
			if (file_exists($_SERVER['DOCUMENT_ROOT'] . $componentFolder) && ($handle = opendir($_SERVER['DOCUMENT_ROOT'] . $componentFolder)))
			{
				while (($file = readdir($handle)) !== false)
				{
					if ($file == "bitrix" || $file == ".." || $file == ".")
					{
						continue;
					}

					$dir = $componentFolder . "/" . $file;
					if (is_dir($_SERVER['DOCUMENT_ROOT'] . $dir))
					{
						if (CComponentUtil::isComponent($dir))
						{
							$components[] = [
								"path" => $dir,
								"name" => $file,
							];
						}
						elseif ($comp_handle = opendir($_SERVER['DOCUMENT_ROOT'] . $dir))
						{
							while (($subdir = readdir($comp_handle)) !== false)
							{
								if ($subdir == ".." || $subdir == "." || $subdir == ".svn")
								{
									continue;
								}

								if (CComponentUtil::isComponent($dir . "/" . $subdir))
								{
									$components[] = [
										"path" => $dir . "/" . $subdir,
										"name" => $file . ":" . $subdir,
									];
								}
							}
							closedir($comp_handle);
						}
					}
				}
				closedir($handle);
			}
		}
		if (isset($arParams["ACTION"]) && $arParams["ACTION"] == "FIND")
		{
			foreach ($components as $component)
			{
				$arResult["MESSAGE"]["DETAIL"] .= $component["name"] . " \n";
			}

			if ($arResult["MESSAGE"]["DETAIL"] == '')
			{
				$arResult["MESSAGE"]["PREVIEW"] = GetMessage("CL_HAVE_NO_CUSTOM_COMPONENTS");
			}
			else
			{
				$arResult = [
					"STATUS" => true,
					"MESSAGE" => [
						"PREVIEW" => GetMessage("CL_HAVE_CUSTOM_COMPONENTS") . " (" . count($components) . ")",
						"DETAIL" => $arResult["MESSAGE"]["DETAIL"],
					],
				];
			}
		}
		else
		{
			foreach ($components as $component)
			{
				$desc = $_SERVER['DOCUMENT_ROOT'] . $component["path"] . "/.description.php";
				if (!file_exists($desc) || filesize($desc) === 0)
				{
					$arResult["MESSAGE"]["DETAIL"] .= GetMessage("CL_EMPTY_DESCRIPTION") . " " . $component["name"] . " \n";
				}
			}

			if (!isset($arResult["MESSAGE"]["DETAIL"]) || $arResult["MESSAGE"]["DETAIL"] == '')
			{
				$arResult["STATUS"] = true;
				$arResult["MESSAGE"]["PREVIEW"] = GetMessage("CL_HAVE_CUSTOM_COMPONENTS_DESC");
			}
			else
			{
				$arResult = [
					"STATUS" => false,
					"MESSAGE" => [
						"PREVIEW" => GetMessage("CL_ERROR_FOUND_SHORT"),
						"DETAIL" => $arResult["MESSAGE"]["DETAIL"],
					],
				];
			}
		}
		return $arResult;
	}

	public static function CheckBackup()
	{
		$arCount = 0;
		$arResult = [];
		$arResult["STATUS"] = false;
		$bBitrixCloud = function_exists('openssl_encrypt') && CModule::IncludeModule('bitrixcloud') && CModule::IncludeModule('clouds');

		$site = CSite::GetSiteByFullPath($_SERVER['DOCUMENT_ROOT']);
		$path = BX_ROOT . "/backup";
		$arTmpFiles = [];
		$arFilter = [];
		GetDirList([$site, $path], $arDir, $arTmpFiles, $arFilter, ['sort' => 'asc'], "F");

		foreach ($arTmpFiles as $ar)
		{
			if (mb_strpos($ar['NAME'], ".enc.gz") || mb_strpos($ar['NAME'], ".tar.gz") || mb_strpos($ar['NAME'], ".tar") || mb_strpos($ar['NAME'], ".enc"))
			{
				$arCount++;
			}
		}

		if ($bBitrixCloud)
		{
			$backup = CBitrixCloudBackup::getInstance();
			try
			{
				foreach ($backup->listFiles() as $ar)
				{
					if (mb_strpos($ar['FILE_NAME'], ".enc.gz") || mb_strpos($ar['FILE_NAME'], ".tar.gz") || mb_strpos($ar['FILE_NAME'], ".tar") || mb_strpos($ar['FILE_NAME'], ".enc"))
					{
						$arCount++;
					}
				}
			}
			catch (Exception)
			{
			}
		}
		if ($arCount > 0)
		{
			$arResult["STATUS"] = true;
			$arResult["MESSAGE"]["PREVIEW"] = GetMessage("CL_FOUND_BACKUP", ["#count#" => $arCount]);
		}
		else
		{
			$arResult["MESSAGE"]["PREVIEW"] = GetMessage("CL_NOT_FOUND_BACKUP");
		}
		return $arResult;
	}

	public static function CheckTemplates()
	{
		$arFolders = [
			$_SERVER['DOCUMENT_ROOT'] . "/bitrix/templates",
			$_SERVER['DOCUMENT_ROOT'] . "/local/templates",
		];
		$arResult["STATUS"] = false;
		$arCount = 0;
		$arRequireFiles = ["header.php", "footer.php"];
		$arFilter = [".svn", ".", ".."];
		$arMessage = '';
		foreach ($arFolders as $folder)
		{
			if (file_exists($folder) && ($arTemplates = scandir($folder)))
			{
				foreach ($arTemplates as $dir)
				{
					$arTemplateFolder = $folder . "/" . $dir;
					if (in_array($dir, $arFilter) || !is_dir($arTemplateFolder))
					{
						continue;
					}
					$arRequireFilesTmp = $arRequireFiles;

					foreach ($arRequireFilesTmp as $k => $file)
					{
						if (!file_exists($arTemplateFolder . "/" . $file))
						{
							$arMessage .= GetMessage("NOT_FOUND_FILE", ["#template#" => $dir, "#file_name#" => $file]) . "\n";
							unset($arRequireFilesTmp[$k]);
						}
					}

					if (in_array("header.php", $arRequireFilesTmp))
					{
						if (file_exists($arTemplateFolder . '/header.php'))
						{
							$header = file_get_contents($arTemplateFolder . '/header.php');

							if ($header != '')
							{
								preg_match('/\$APPLICATION->ShowHead\(/im', $header, $arShowHead);
								preg_match('/\$APPLICATION->ShowTitle\(/im', $header, $arShowTitle);
								preg_match('/\$APPLICATION->ShowPanel\(/im', $header, $arShowPanel);
								if (!in_array($dir, ['mail_join']) && empty($arShowHead))
								{
									preg_match_all('/\$APPLICATION->(ShowCSS|ShowHeadScripts|ShowHeadStrings)\(/im', $header, $arShowHead);
									if (!$arShowHead[0] || count($arShowHead[0]) != 3)
									{
										$arMessage .= GetMessage("NO_SHOWHEAD", ["#template#" => $dir]) . "\n";
									}
								}
								if (!in_array($dir, ['empty', 'mail_join']) && empty($arShowTitle))
								{
									$arMessage .= GetMessage("NO_SHOWTITLE", ["#template#" => $dir]) . "\n";
								}
								if (!in_array($dir, ['mobile_app', 'desktop_app', 'empty', 'learning_10_0_0', 'call_app', 'mail_join', 'dashboard_detail', 'booking_pub']) && empty($arShowPanel))
								{
									$arMessage .= GetMessage("NO_SHOWPANEL", ["#template#" => $dir]) . "\n";
								}
							}
						}
					}

					$arCount++;
				}
			}
		}

		if ($arCount > 0 && $arMessage == '')
		{
			$arResult["STATUS"] = true;
		}

		$arResult["MESSAGE"] = [
			"PREVIEW" => $arCount > 0 ? GetMessage("TEMPLATE_CHECK_COUNT", ["#count#" => $arCount]) : GetMessage("NOT_FOUND_TEMPLATE"),
			"DETAIL" => $arMessage,
		];

		return $arResult;
	}

	public static function CheckKernel($arParams)
	{
		$installFilesMapping = [
			"install/components/bitrix/" => "/bitrix/components/bitrix/",
			"install/js/" => "/bitrix/js/",
			"install/activities/" => "/bitrix/activities/",
			"install/admin/" => "/bitrix/admin/",
			"install/wizards/" => "/bitrix/wizards/",
		];

		$events = EventManager::getInstance()->findEventHandlers("main", "onKernelCheckInstallFilesMappingGet");
		foreach ($events as $event)
		{
			$pathList = ExecuteModuleEventEx($event);
			if (is_array($pathList))
			{
				foreach ($pathList as $pathFrom => $pathTo)
				{
					if (!isset($installFilesMapping[$pathFrom]))
					{
						$installFilesMapping[$pathFrom] = $pathTo;
					}
				}
			}
		}

		$modules = ModuleManager::getModulesFromDisk(false);
		ksort($modules);
		$modulesList = array_keys($modules);

		$session = $arParams["SESSION"] ?? [];

		if (!$arParams["STEP"])
		{
			$session = [
				"MNUM" => 0,
				"FILES_COUNT" => 0,
				"MOD_FILES_COUNT" => 0,
				"UNKNOWN_FILES_COUNT" => 0,
			];
		}

		$docRoot = rtrim($_SERVER["DOCUMENT_ROOT"], '/');
		$arError = false;
		$moduleId = $modulesList[$session["MNUM"]];
		$moduleFolder = $docRoot . "/bitrix/modules/" . $moduleId . "/";
		$fileCount = 0;
		$modifiedFileCount = 0;
		$unknownFileCount = 0;
		$state = [];
		$skip = false;

		$ver = $modules[$moduleId]['version'];
		if (!$ver)
		{
			$state = [
				"STATUS" => false,
				"MESSAGE" => GetMessage("CL_MODULE_VERSION_ERROR", ["#module_id#" => $moduleId]) . "\n",
			];
			$arError = true;
		}
		else
		{
			$result = (new Checksum())->getHashes($moduleId, $ver, true);

			$message = "";
			$unknownMessage = "";
			if (empty($result["error"]))
			{
				$except = [
					'SUBDIR' => [
						'lang' => ['de', 'en', 'kz', 'ru'], // count only languages from repo
					],
					"FOLDERS" => [
						"ua",
						$docRoot . "/bitrix/modules/main/install/templates/lang", // deleted components 1.0
					],
					"FILES" => [
						$docRoot . "/bitrix/modules/main/admin/define.php",
					],
				];
				$moduleFiles = [];
				CCheckListTools::__scandir($moduleFolder, $moduleFiles, $except);

				$moduleFolderLength = strlen($moduleFolder);
				$fileCount = count($moduleFiles);

				foreach ($moduleFiles as $file)
				{
					$relFile = substr($file, $moduleFolderLength);

					if (isset($result[$relFile]))
					{
						$checksum = $result[$relFile];
						if ($checksum == 'n/a')
						{
							continue;
						}

						if (md5_file($file) !== $checksum)
						{
							$message .= str_replace(["//", "\\\\"], ["/", "\\"], $file) . "\n";
							$modifiedFileCount++;
						}

						foreach ($installFilesMapping as $key => $value)
						{
							if (str_starts_with($relFile, $key))
							{
								$filePath = str_replace($key, $docRoot . $value, $relFile);
								if (file_exists($filePath) && md5_file($filePath) !== $checksum)
								{
									$modifiedFileCount++;
									$message .= str_replace(["//", "\\\\"], ["/", "\\"], $filePath) . "\n";
								}
							}
						}
					}
					else
					{
						$unknownFileCount++;
						$unknownMessage .= str_replace(["//", "\\\\"], ["/", "\\"], $file) . "\n";
					}
				}

				if ($message != '' || $unknownMessage != '')
				{
					$state = [
						"MESSAGE" => $message,
						"UNKNOWN_MESSAGE" => $unknownMessage,
						"STATUS" => false,
					];
				}
			}
			else
			{
				if ($result["error"] != "unknow module id")
				{
					$state["MESSAGE"] = GetMessage("CL_CANT_CHECK", ["#module_id#" => $moduleId]) . "\n";
					$arError = true;
				}
				else
				{
					$skip = true;
				}
			}
		}
		if (!$arError && !$skip)
		{
			if (empty($state["MESSAGE"]) && empty($state["UNKNOWN_MESSAGE"]))
			{
				$session["MESSAGE"][$moduleId] = GetMessage("CL_NOT_MODIFIED", ["#module_id#" => $moduleId]) . "\n";
			}
			else
			{
				$session["MESSAGE"][$moduleId] = '';
				if (!empty($state["MESSAGE"]))
				{
					$session["MESSAGE"][$moduleId] .= GetMessage("CL_MODIFIED_FILES", ["#module_id#" => $moduleId]) . "\n" . $state["MESSAGE"];
				}
				if (!empty($state["UNKNOWN_MESSAGE"]))
				{
					$session["MESSAGE"][$moduleId] .= GetMessage("CL_UNKNOWN_FILES", ["#module_id#" => $moduleId]) . "\n" . $state["UNKNOWN_MESSAGE"];
				}
			}
			$session["FILES_COUNT"] += $fileCount;
			$session["MOD_FILES_COUNT"] += $modifiedFileCount;
			$session["UNKNOWN_FILES_COUNT"] += $unknownFileCount;
		}
		if ((isset($state["STATUS"]) && $state["STATUS"] === false) || $arError)
		{
			$session["STATUS"] = false;
		}

		$modulesCount = count($modulesList);
		if (($session["MNUM"] + 1) >= $modulesCount)
		{
			$arDetailReport = "";
			foreach ($session["MESSAGE"] as $moduleMessage)
			{
				$arDetailReport .= "<div class=\"checklist-dot-line\"></div>" . $moduleMessage;
			}

			$arResult = [
				"MESSAGE" => [
					"PREVIEW" => GetMessage("CL_KERNEL_CHECK_FILES") . $session["FILES_COUNT"] . "<br>" .
						GetMessage("CL_KERNEL_CHECK_MODULE") . $modulesCount . "<br>" .
						GetMessage("CL_KERNEL_CHECK_MODIFIED") . $session["MOD_FILES_COUNT"] . "<br>" .
						GetMessage("CL_KERNEL_CHECK_UNKNOWN") . $session["UNKNOWN_FILES_COUNT"] . "<br>" ,
					"DETAIL" => $arDetailReport,
				],
				"STATUS" => ($session["STATUS"] === false ? false : true),
			];
		}
		else
		{
			$percent = $session["MNUM"] / $modulesCount;
			$session["MNUM"]++;

			$arResult = [
				"IN_PROGRESS" => "Y",
				"PERCENT" => number_format($percent * 100, 2),
				"PARAMS" => ["SESSION" => $session],
			];
		}

		return $arResult;
	}

	public static function CheckSecurity($arParams)
	{
		global $DB;
		$err = 0;
		$arResult['STATUS'] = false;
		$arMessage = '';
		switch ($arParams["ACTION"])
		{
			case "SECURITY_LEVEL":
				if (CModule::IncludeModule("security"))
				{
					if (CSecurityFilterMask::GetList()->Fetch())
					{
						$arMessage .= (++$err) . ". " . GetMessage("CL_FILTER_EXEPTION_FOUND") . "\n";
					}
					if (!CSecurityFilter::IsActive())
					{
						$arMessage .= (++$err) . ". " . GetMessage("CL_FILTER_NON_ACTIVE") . "\n";
					}
					if (COption::GetOptionString("main", "captcha_registration", "N") == "N")
					{
						$arMessage .= (++$err) . ". " . GetMessage("CL_CAPTCHA_NOT_USE") . "\n";
					}

					if (CCheckListTools::AdminPolicyLevel() != "high")
					{
						$arMessage .= (++$err) . ". " . GetMessage("CL_ADMIN_SECURITY_LEVEL") . "\n";
					}
					if (COption::GetOptionInt("main", "error_reporting", E_COMPILE_ERROR | E_ERROR | E_CORE_ERROR | E_PARSE) != (E_COMPILE_ERROR | E_ERROR | E_CORE_ERROR | E_PARSE) && COption::GetOptionString("main", "error_reporting", "") != 0)
					{
						$arMessage .= (++$err) . ". " . GetMessage("CL_ERROR_REPORTING_LEVEL") . "\n";
					}
					if ($DB->debug)
					{
						$arMessage .= (++$err) . ". " . GetMessage("CL_DBDEBUG_TURN_ON") . "\n";
					}
					if ($arMessage)
					{
						$arResult["STATUS"] = false;
						$arResult["MESSAGE"] = [
							"PREVIEW" => GetMessage("CL_MIN_LEVEL_SECURITY"),
							"DETAIL" => GetMessage("CL_ERROR_FOUND") . "\n" . $arMessage,
						];
					}
					else
					{
						$arResult["STATUS"] = true;
						$arResult["MESSAGE"] = [
							"PREVIEW" => GetMessage("CL_LEVEL_SECURITY") . "\n",
						];
					}
				}
				else
				{
					$arResult = [
						"STATUS" => false,
						"MESSAGE" => [
							"PREVIEW" => GetMessage("CL_SECURITY_MODULE_NOT_INSTALLED") . "\n",
						],
					];
				}
				break;
			case "ADMIN_POLICY":
				if (CCheckListTools::AdminPolicyLevel() != "high")
				{
					$arResult["MESSAGE"]["PREVIEW"] = GetMessage("CL_ADMIN_SECURITY_LEVEL") . "\n";
				}
				else
				{
					$arResult = [
						"STATUS" => true,
						"MESSAGE" => [
							"PREVIEW" => GetMessage("CL_ADMIN_SECURITY_LEVEL_IS_HIGH") . "\n",
						],
					];
				}
				break;
		}

		return $arResult;
	}

	public static function CheckErrorReport()
	{
		global $DBDebug;
		$err = 0;
		$arResult["STATUS"] = true;
		$arMessage = '';
		if ($DBDebug)
		{
			$arMessage .= (++$err) . ". " . GetMessage("CL_DBDEBUG_TURN_ON") . "\n";
		}
		if (COption::GetOptionString("main", "error_reporting", "") != 0 && ini_get("display_errors"))
		{
			$arMessage .= (++$err) . ". " . GetMessage("CL_ERROR_REPORT_TURN_ON") . "\n";
		}

		if ($arMessage)
		{
			$arResult["STATUS"] = false;
			$arResult["MESSAGE"] = [
				"PREVIEW" => GetMessage("CL_ERROR_FOUND_SHORT") . "\n",
				"DETAIL" => $arMessage,
			];
		}
		return $arResult;
	}

	public static function IsCacheOn()
	{
		$arResult["STATUS"] = true;
		if (COption::GetOptionString("main", "component_cache_on", "Y") == "N")
		{
			$arResult["STATUS"] = false;
			$arResult["MESSAGE"] = [
				"PREVIEW" => GetMessage("CL_TURNOFF_AUTOCACHE") . "\n",
			];
		}
		else
		{
			$arResult["MESSAGE"] = [
				"PREVIEW" => GetMessage("CL_TURNON_AUTOCACHE") . "\n",
			];
		}

		return $arResult;
	}

	public static function CheckDBPassword()
	{
		$err = 0;
		$arMessage = "";
		$sign = ",.#!*%$:-^@{}[]()'\"-+=<>?`&;";
		$dit = "1234567890";
		$have_sign = false;
		$have_dit = false;
		$arResult["STATUS"] = true;

		$connection = Application::getInstance()->getConnection();
		$password = $connection->getPassword();

		if ($password == '')
		{
			$arMessage .= GetMessage("CL_EMPTY_PASS") . "\n";
		}
		else
		{
			if ($password == mb_strtolower($password))
			{
				$arMessage .= (++$err) . ". " . GetMessage("CL_SAME_REGISTER") . "\n";
			}

			for ($j = 0, $c = mb_strlen($password); $j < $c; $j++)
			{
				if (mb_strpos($sign, $password[$j]) !== false)
				{
					$have_sign = true;
				}
				if (mb_strpos($dit, $password[$j]) !== false)
				{
					$have_dit = true;
				}
				if ($have_dit && $have_sign)
				{
					break;
				}
			}

			if (!$have_dit)
			{
				$arMessage .= (++$err) . ". " . GetMessage("CL_HAVE_NO_DIT") . "\n";
			}
			if (!$have_sign)
			{
				$arMessage .= (++$err) . ". " . GetMessage("CL_HAVE_NO_SIGN") . "\n";
			}
			if (mb_strlen($password) < 8)
			{
				$arMessage .= (++$err) . ". " . GetMessage("CL_LEN_MIN") . "\n";
			}
		}
		if ($arMessage)
		{
			$arResult["STATUS"] = false;
			$arResult["MESSAGE"] = [
				"PREVIEW" => GetMessage("CL_ERROR_FOUND_SHORT"),
				"DETAIL" => $arMessage,
			];
		}
		else
		{
			$arResult["MESSAGE"] = [
				"PREVIEW" => GetMessage("CL_NO_ERRORS"),
			];
		}
		return $arResult;
	}

	public static function CheckPerfomance($arParams)
	{
		if (!IsModuleInstalled("perfmon"))
		{
			return [
				"STATUS" => false,
				"MESSAGE" => [
					"PREVIEW" => GetMessage("CL_CHECK_PERFOM_NOT_INSTALLED"),
				],
			];
		}
		$arResult = [
			"STATUS" => true,
		];
		switch ($arParams["ACTION"])
		{
			case "PHPCONFIG":
				if (COption::GetOptionString("perfmon", "mark_php_is_good", "N") == "N")
				{
					$arResult["STATUS"] = false;
					$arResult["MESSAGE"] = [
						"PREVIEW" => GetMessage("CL_PHP_NOT_OPTIMAL", ["#LANG#" => LANG]) . "\n",
					];
				}
				else
				{
					$arResult["MESSAGE"] = [
						"PREVIEW" => GetMessage("CL_PHP_OPTIMAL") . "\n",
					];
				}
				break;
			case "PERF_INDEX":
				$arPerfIndex = COption::GetOptionString("perfmon", "mark_php_page_rate", "N");
				if ($arPerfIndex == "N")
				{
					$arResult["STATUS"] = false;
					$arResult["MESSAGE"] = [
						"PREVIEW" => GetMessage("CL_CHECK_PERFOM_FAILED", ["#LANG#" => LANG]) . "\n",
					];
				}
				elseif ($arPerfIndex < 15)
				{
					$arResult["STATUS"] = false;
					$arResult["MESSAGE"] = [
						"PREVIEW" => GetMessage("CL_CHECK_PERFOM_LOWER_OPTIMAL", ["#LANG#" => LANG]) . "\n",
					];
				}
				else
				{
					$arResult["MESSAGE"] = [
						"PREVIEW" => GetMessage("CL_CHECK_PERFOM_PASSED") . "\n",
					];
				}
				break;
		}
		return $arResult;
	}

	public static function CheckQueryString($arParams = [])
	{
		$time = time();
		$arPath = [
			$_SERVER["DOCUMENT_ROOT"] . "/",
			$_SERVER["DOCUMENT_ROOT"] . "/bitrix/templates/",
			$_SERVER["DOCUMENT_ROOT"] . "/bitrix/php_interface/",
			$_SERVER["DOCUMENT_ROOT"] . "/bitrix/components/",
		];
		$arExept = [
			"FOLDERS" => ["images", "bitrix", "upload", ".svn"],
			"EXT" => ["php"],
			"FILES" => [
				$_SERVER["DOCUMENT_ROOT"] . "/bitrix/php_interface/dbconn.php",
				"after_connect.php",
			],
		];

		$arParams["STEP"] = (intval($arParams["STEP"]) >= 0) ? intval($arParams["STEP"]) : 0;
		$session = $arParams["SESSION"] ?? [];

		if (empty($session) || $arParams["STEP"] == 0)
		{
			$session = [
				"LAST_FILE" => "",
				"FOUND" => "",
				"PERCENT" => 0,
			];
			$files = [];
			$arPathTmp = $arPath;
			foreach ($arPathTmp as $path)
			{
				CCheckListTools::__scandir($path, $files, $arExept);
			}
			$session["COUNT"] = count($files);
		}

		$arFileNum = 0;
		foreach ($arPath as $namespace)
		{
			$files = [];
			CCheckListTools::__scandir($namespace, $files, $arExept);
			foreach ($files as $file)
			{
				$arFileNum++;
				//this is not first step?
				if (!empty($session["LAST_FILE"]))
				{
					if ($session["LAST_FILE"] == $file)
					{
						$session["LAST_FILE"] = "";
					}
					continue;
				}

				if ($content = file_get_contents($file))
				{
					$queries = [];
					preg_match('/((?:mysql_query|mysqli_query|odbc_exec|oci_execute|odbc_execute)\(.*\))/ism', $content, $queries);

					if ($queries && !empty($queries[0]))
					{
						$session["FOUND"] .= str_replace(["//", "\\\\"], ["/", "\\"], $file) . "\n";
					}
				}

				if (time() - $time >= 20)
				{
					$session["LAST_FILE"] = $file;
					return [
						"IN_PROGRESS" => "Y",
						"PERCENT" => number_format($arFileNum / $session["COUNT"] * 100, 2),
						"PARAMS" => ["SESSION" => $session],
					];
				}
			}
		}
		$arResult = ["STATUS" => true];
		if (!empty($session["FOUND"]))
		{
			$arResult["STATUS"] = false;
			$arResult["MESSAGE"] = [
				"PREVIEW" => GetMessage("CL_KERNEL_CHECK_FILES") . $arFileNum . ".\n" . GetMessage("CL_ERROR_FOUND_SHORT") . "\n",
				"DETAIL" => GetMessage("CL_DIRECT_QUERY_TO_DB") . "\n" . $session["FOUND"],
			];
		}
		else
		{
			$arResult["MESSAGE"] = [
				"PREVIEW" => GetMessage("CL_KERNEL_CHECK_FILES") . $arFileNum . "\n",
			];
		}

		return $arResult;
	}

	public static function KeyCheck()
	{
		$arResult = ["STATUS" => false];
		$arUpdateList = CUpdateClient::GetUpdatesList($errorMessage, LANG);
		if (array_key_exists("CLIENT", $arUpdateList) && $arUpdateList["CLIENT"][0]["@"]["RESERVED"] == "N")
		{
			$arResult = [
				"STATUS" => true,
				"MESSAGE" => ["PREVIEW" => GetMessage("CL_LICENSE_KEY_ACTIVATE")],
			];
		}
		else
		{
			$arResult["MESSAGE"] = ["PREVIEW" => GetMessage("CL_LICENSE_KEY_NONE_ACTIVATE", ["#LANG#" => LANG])];
		}

		return $arResult;
	}

	public static function CheckVMBitrix()
	{
		$arResult = ["STATUS" => true];

		$vm = new BitrixVm();
		$ver = $vm->getVersion();

		if ($ver)
		{
			if (version_compare($ver, $vm->getAvailableVersion()) >= 0)
			{
				$arResult["MESSAGE"] = [
					'PREVIEW' => GetMessage("CL_VMBITRIX_ACTUAL"),
				];
			}
			else
			{
				$arResult["STATUS"] = false;
				$arResult["MESSAGE"] = [
					'PREVIEW' => GetMessage("CL_VMBITRIX_NOT_ACTUAL"),
				];
			}
		}

		return $arResult;
	}

	public static function CheckSiteCheckerStatus()
	{
		$arResult = [];
		$arResult["STATUS"] = false;

		$checkerStatus = COption::GetOptionString('main', 'site_checker_success', 'N');
		if ($checkerStatus == 'Y')
		{
			$arResult["STATUS"] = true;
			$arResult["MESSAGE"] = [
				'PREVIEW' => GetMessage("CL_SITECHECKER_OK", ["#LANG#" => LANG]),
			];
		}
		else
		{
			$arResult["MESSAGE"] = [
				'PREVIEW' => GetMessage("CL_SITECHECKER_NOT_OK", ["#LANG#" => LANG]),
			];
		}

		return $arResult;
	}

	public static function CheckSecurityScannerStatus()
	{
		$arResult = [];
		$arResult["STATUS"] = false;

		if (!Loader::includeModule('security'))
		{
			return $arResult;
		}

		$lastTestingInfo = CSecuritySiteChecker::getLastTestingInfo();
		$criticalResultsCount = CSecuritySiteChecker::calculateCriticalResults($lastTestingInfo["results"] ?? []);

		if ((time() - MakeTimeStamp($lastTestingInfo['test_date'] ?? '', FORMAT_DATE)) > 60 * 60 * 24 * 30)
		{
			$arResult["MESSAGE"] = [
				'PREVIEW' => GetMessage("CL_SECURITYSCANNER_OLD", ["#LANG#" => LANG]),
			];
		}
		elseif ($criticalResultsCount === 0)
		{
			$arResult["STATUS"] = true;
			$arResult["MESSAGE"] = [
				'PREVIEW' => GetMessage("CL_SECURITYSCANNER_OK"),
			];
		}
		else
		{
			$arResult["MESSAGE"] = [
				'PREVIEW' => GetMessage("CL_SECURITYSCANNER_NOT_OK", ["#LANG#" => LANG]),
			];
		}

		return $arResult;
	}
}
