<?php

namespace Bitrix\Translate;

use Bitrix\Main;
use Bitrix\Main\Error;
use Bitrix\Main\Localization;
use Bitrix\Main\Localization\Loc;
use Bitrix\Translate;


abstract class ComponentBase
	extends \CBitrixComponent
	implements Translate\IErrorable
{
	use Translate\Error;
	use Translate\Warning;

	const STATUS_SUCCESS = 'success';
	const STATUS_DENIED = 'denied';
	const STATUS_ERROR = 'error';

	const TEMPLATE_ERROR = 'error';

	/** @var string */
	protected $path = '';

	/** @var int Session tab counter. */
	protected $tabId = 0;


	/**
	 * @return boolean
	 */
	protected function checkModuleAvailability()
	{
		if (!Main\Loader::includeModule('translate'))
		{
			if ($this->isAjaxRequest())
			{
				$this->sendJsonResponse(new Error('Module "translate" is not installed.', self::STATUS_ERROR));
			}
			else
			{
				$this->addError(new Error('Module "translate" is not installed.', self::STATUS_ERROR));
				$this->includeComponentTemplate(self::TEMPLATE_ERROR);
			}

			return false;
		}

		return true;
	}

	/**
	 * Checks if user has permission to view language file.
	 * @param \CUser $user User to check permissions.
	 * @return boolean
	 */
	protected function hasUserPermissionView($user)
	{
		return Translate\Permission::canView($user);
	}

	/**
	 * Checks if user has permission to edit language file.
	 * @param \CUser $user User to check permissions.
	 * @return boolean
	 */
	protected function hasUserPermissionEdit($user)
	{
		return Translate\Permission::canEdit($user);
	}
	/**
	 * Checks if user has permission to edit source language file.
	 * @param \CUser $user User to check permissions.
	 * @return boolean
	 */
	protected function hasUserPermissionEditSource($user)
	{
		return Translate\Permission::canEditSource($user);
	}

	/**
	 * Checks if user has permission to view language file.
	 * @return boolean
	 */
	protected function checkPermissionView()
	{
		if (!$this->hasUserPermissionView($this->getUser()))
		{
			if ($this->isAjaxRequest())
			{
				$this->sendJsonResponse(new Error(Loc::getMessage('TRANSLATE_FILTER_ERROR_ACCESS_DENIED'), self::STATUS_DENIED));
			}
			else
			{
				$this->addError(new Error(Loc::getMessage('TRANSLATE_FILTER_ERROR_ACCESS_DENIED'), self::STATUS_DENIED));
				$this->includeComponentTemplate(self::TEMPLATE_ERROR);
			}

			return false;
		}

		return true;
	}

	/**
	 * Checks if user has permission to edit language file.
	 * @return boolean
	 */
	protected function checkPermissionEdit()
	{
		if (!$this->hasUserPermissionEdit($this->getUser()))
		{
			if ($this->isAjaxRequest())
			{
				$this->sendJsonResponse(new Error(Loc::getMessage('TRANSLATE_FILTER_ERROR_WRITING_RIGHTS'), self::STATUS_DENIED));
			}
			else
			{
				$this->addError(new Error(Loc::getMessage('TRANSLATE_FILTER_ERROR_WRITING_RIGHTS'), self::STATUS_DENIED));
				$this->includeComponentTemplate(self::TEMPLATE_ERROR);
			}

			return false;
		}

		return true;
	}

	/**
	 * Checks if user has permission to edit source language file.
	 * @return boolean
	 */
	protected function checkPermissionEditPhp()
	{
		if (!$this->hasUserPermissionEditSource($this->getUser()))
		{
			if ($this->isAjaxRequest())
			{
				$this->sendJsonResponse(new Error(Loc::getMessage('TRANSLATE_FILTER_ERROR_PHP_EDIT_RIGHTS'), self::STATUS_DENIED));
			}
			else
			{
				$this->addError(new Error(Loc::getMessage('TRANSLATE_FILTER_ERROR_PHP_EDIT_RIGHTS'), self::STATUS_DENIED));
				$this->includeComponentTemplate(self::TEMPLATE_ERROR);
			}

			return false;
		}

		return true;
	}

	/**
	 * Checks some mysql config variables.
	 * @return void
	 */
	protected function checkModuleStepper(): void
	{
		$stepper = \Bitrix\Main\Update\Stepper::getHtml('translate', Loc::getMessage('TRANSLATE_INDEX_STEPPER'));
		if (!empty($stepper))
		{
			$this->arResult['STEPPER'] = $stepper;
		}
	}

	/**
	 * Checks FTS if tables exists.
	 * @return void
	 */
	protected function checkFtsTables(): void
	{
		Translate\Index\Internals\PhraseFts::checkTables();
	}

	/**
	 * Checks some mysql config variables.
	 * @return void
	 */
	protected function checkMysqlConfig(): void
	{
		$majorVersion = (int)\mb_substr(\Bitrix\Main\Application::getConnection()->getVersion()[0], 0, 1);

		if ($majorVersion >= 8)
		{
			$conf = Main\Application::getConnection()->query("SHOW VARIABLES LIKE 'regexp_time_limit'")->fetch();
			if ($conf['Variable_name'] == 'regexp_time_limit')
			{
				if ((int)$conf['Value'] <= 0)
				{
					$this->addWarning(new Error(Loc::getMessage('TRANSLATE_MYSQL_CONFIG_ERROR_REGEXP_TIME_LIMIT'), self::STATUS_ERROR));
				}
			}
		}
	}

	/**
	 * @return void
	 */
	protected function prepareParams()
	{
		$params =& $this->getParams();
		if (empty($params['CURRENT_LANG']))
		{
			$params['CURRENT_LANG'] = Loc::getCurrentLang();
		}
		if (empty($params['LIST_PATH']))
		{
			$params['LIST_PATH'] = '/bitrix/admin/translate_list.php';
		}
		if (empty($params['EDIT_PATH']))
		{
			$params['EDIT_PATH'] = '/bitrix/admin/translate_edit.php';
		}
		if (empty($params['SHOW_SOURCE_PATH']))
		{
			$params['SHOW_SOURCE_PATH'] = '/bitrix/admin/translate_show_php.php';
		}
		if (empty($params['EDIT_SOURCE_PATH']))
		{
			$params['EDIT_SOURCE_PATH'] = '/bitrix/admin/translate_edit_php.php';
		}

		$params['SET_TITLE'] = isset($params['SET_TITLE']) ? $params['SET_TITLE'] === 'Y' : true;

		$this->arResult['IS_AJAX_REQUEST'] = $this->isAjaxRequest();

		$this->arResult['ALLOW_VIEW'] = $this->hasUserPermissionView($this->getUser());
		$this->arResult['ALLOW_EDIT'] = $this->hasUserPermissionEdit($this->getUser());
		$this->arResult['ALLOW_EDIT_SOURCE'] = $this->hasUserPermissionEditSource($this->getUser());
	}

	/**
	 * Moves current language to the first position.
	 *
	 * @param string[] $languageList
	 * @param string $currentLangId
	 *
	 * @return string[]
	 */
	protected function rearrangeLanguages($languageList, $currentLangId)
	{
		$inx = \array_search($currentLangId, $languageList, true);
		if ($inx !== false)
		{
			unset($languageList[$inx]);
		}

		\array_unshift($languageList, $currentLangId);

		return $languageList;
	}

	/**
	 * @return string[]
	 */
	protected function getLanguages()
	{
		static $languagesList;

		if (empty($languagesList))
		{
			$languagesList = Translate\Config::getEnabledLanguages();
		}

		return $languagesList;
	}

	/**
	 * Returns list of language names from the site settings.
	 *
	 * @param string[] $languageIds Languages list to get name.
	 *
	 * @return array
	 */
	protected function getLanguagesTitle($languageIds)
	{
		$titles = Translate\Config::getLanguagesTitle($languageIds);
		array_walk($titles, function(&$title, $langId) { $title = "{$title} ({$langId})"; });

		return $titles;
	}

	/**
	 * Return languages compatible by their encoding.
	 *
	 * @return string[]
	 */
	protected function getCompatibleLanguages()
	{
		static $languages = array();
		if (empty($languages))
		{
			$currentEncoding = Localization\Translation::getCurrentEncoding();
			$currentLang = Loc::getCurrentLang();
			$limitEncoding = !($currentEncoding == 'utf-8' || Localization\Translation::useTranslationRepository());

			$isEncodingCompatible = function ($langId) use ($limitEncoding, $currentEncoding, $currentLang)
			{
				$compatible = true;
				if ($limitEncoding)
				{
					$compatible = (
						$langId == $currentLang ||
						Translate\Config::getCultureEncoding($langId) == $currentEncoding ||
						$langId == 'en'
					);
				}

				return $compatible;
			};

			$enabledLanguages = $this->getLanguages();
			foreach ($enabledLanguages as $langId)
			{
				if ($limitEncoding && !$isEncodingCompatible($langId))
				{
					continue;
				}
				$languages[] = $langId;
			}
		}

		return $languages;
	}

	/**
	 * @return string
	 */
	protected function detectTabId()
	{
		$tabId = $this->request->get('tabId');
		if (!empty($tabId) && (int)$tabId > 0)
		{
			$this->tabId = (int)$tabId;
		}
		elseif ($this->isAjaxRequest())
		{
			$this->tabId = Translate\Filter::getTabId(false);
		}
		else
		{
			$this->tabId = Translate\Filter::getTabId();
		}

		return $this->tabId;
	}

	/**
	 * @return string
	 */
	protected function detectStartingPath(?string $path = ''): string
	{
		$home = Translate\Config::getDefaultPath();

		$initPaths = Translate\Config::getInitPath();
		if (count($initPaths) > 0)
		{
			$home = $initPaths[0];
			if (!empty($path))
			{
				foreach ($initPaths as $initPath)
				{
					if (\mb_strpos($path, $initPath) === 0)
					{
						$home = $initPath;
						break;
					}
				}
			}
		}

		return $home;
	}


	/**
	 * Returns component calling params by reference.
	 * @return array
	 */
	public function &getParams()
	{
		return $this->arParams;
	}

	/**
	 * Returns component resulting array by reference.
	 * @return array
	 */
	public function &getResult()
	{
		return $this->arResult;
	}


	/**
	 * @return \CUser
	 */
	protected function getUser()
	{
		/** @global \CUser $USER */
		global $USER;
		return $USER;
	}

	/**
	 * @return \CMain
	 */
	protected function getApplication()
	{
		/** @global \CMain $APPLICATION */
		global $APPLICATION;
		return $APPLICATION;
	}

	/**
	 * Returns whether this is an AJAX (XMLHttpRequest) request.
	 * @return boolean
	 */
	protected function isAjaxRequest()
	{
		return
			($this->request->isAjaxRequest() || $this->request->get('AJAX_CALL') !== null) &&
			$this->request->getRequestMethod() == 'POST';
	}

	/**
	 * Sends Json response to client.
	 *
	 * @param array|object|Main\Error $response Response to send.
	 *
	 * @throws Main\ArgumentException
	 * @return void
	 */
	protected function sendJsonResponse($response)
	{
		$this->getApplication()->restartBuffer();

		$answer = Main\Application::getInstance()->getContext()->getResponse();

		if ($response instanceof Main\Error)
		{
			$this->addError($response);
			$response = array();
		}

		$response['result'] = true;
		if ($this->hasErrors())
		{
			$answer->setStatus('500 Internal Server Error');

			$response['status'] = self::STATUS_ERROR;
			$errors = array();
			foreach ($this->getErrors() as $error)
			{
				/** @var Main\Error $error */
				$errors[] = array(
					'message' => $error->getMessage(),
					'code' => $error->getCode(),
				);
			}
			$response['result'] = false;
			$response['errors'] = $errors;
		}
		elseif (!isset($response['status']))
		{
			$response['status'] = self::STATUS_SUCCESS;
		}

		$answer->addHeader('Content-Type', 'application/x-javascript; charset=UTF-8');
		echo Main\Web\Json::encode($response);

		\CMain::finalActions();
	}

	/**
	 * Drops saved options.
	 * @param string $category Group option name.
	 * @param string $nameMask Option name mask.
	 * @return void
	 */
	protected function clearSavedOptions($category, $nameMask)
	{
		$res = \CUserOptions::getList(false,['CATEGORY' => $category, 'USER_ID' => $this->getUser()->getId(), 'NAME_MASK' => $nameMask]);
		while ($opt = $res->fetch())
		{
			\CUserOptions::deleteOption($category, $opt['NAME']);
		}
	}

	/**
	 * Finds way to get back.
	 *
	 * @param string $path Path to analise.
	 *
	 * @return string
	 */
	protected function detectPathBack($path)
	{
		static $pathBackCache = array();;
		if (!isset($pathBackCache[$path]))
		{
			$pathBack = \dirname($path);
			$slash = \explode('/', $pathBack);
			if (\is_array($slash))
			{
				$slashTmp = $slash;
				$langKey = \array_search('lang', $slash) + 1;
				unset($slashTmp[$langKey]);
				if ($langKey == \count($slash) - 1)
				{
					unset($slash[$langKey]);
					$pathBack = \implode('/', $slash);
				}
			}
			$pathBackCache[$path] = $pathBack;
		}

		return $pathBackCache[$path];
	}

	/**
	 * Get data for chain links.
	 *
	 * @param string $path Path to analise.
	 *
	 * @return array
	 */
	protected function generateChainLinks($path)
	{
		static $chainCache = [];
		if (!isset($chainCache[$path]))
		{
			$params =& $this->getParams();
			$chain = array();
			$slash = \explode('/', \dirname($path));
			if (\is_array($slash))
			{
				$langKey = \array_search('lang', $slash) + 1;
				$slash[$langKey] = $params['CURRENT_LANG'];
				if ($langKey == \count($slash) - 1)
				{
					unset($slash[$langKey]);
				}
				$i = 0;
				$pathList = array();
				foreach ($slash as $dir)
				{
					$i++;
					if ($i == 1)
					{
						$chain[] = array(
							'link' => $params['LIST_PATH'].
											'?lang='.$params['CURRENT_LANG'].
											'&tabId='.$this->tabId.
											'&path=/',
							'title' => '..'
						);
					}
					else
					{
						$pathList[] = \htmlspecialcharsbx($dir);
						$chain[] = array(
							'link' => $params['LIST_PATH'].
											'?lang='.$params['CURRENT_LANG'].
											'&tabId='.$this->tabId.
											'&path=/'.\implode('/', $pathList).'/',
							'title' => \htmlspecialcharsbx($dir)
						);
					}
				}
			}
			$chainCache[$path] = $chain;
		}

		return $chainCache[$path];
	}

	public function getTopIndexedFolders(int $depth = 2): array
	{
		static $list;
		if ($list === null)
		{
			$list = [0 => ''];
			$res = Index\Internals\PathIndexTable::getList([
				'select' => ['ID', 'PATH'],
				'filter' => [
					'=INDEXED' => 'Y',
					'=IS_DIR' => 'Y',
					'<=DEPTH_LEVEL' => $depth,
				],
				'order' => [
					'DEPTH_LEVEL' => 'ASC',
					'PATH' => 'ASC',
				]
			]);
			while ($row = $res->fetch())
			{
				$list[$row['ID']] = $row['PATH'];
			}
		}

		return $list;
	}
}