<?
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\IO\File;
use Bitrix\Main\Localization\Loc;
use Bitrix\Rest\Configuration\Setting;
use Bitrix\Rest\Configuration\Controller;
use Bitrix\Rest\Configuration\Helper;
use Bitrix\Rest\Configuration\Manifest;
use Bitrix\Rest\Configuration\Structure;
use Bitrix\Rest\Configuration\Core\StorageTable;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Web\Json;
use Bitrix\Main\FileTable;
use Bitrix\Main\Web\HttpClient;

class CRestConfigurationExportComponent extends CBitrixComponent implements Controllerable
{
	/** @var ErrorCollection $errors */
	protected $errors;
	protected $type = 'configuration';
	protected $contextPostfix = 'export';

	public function __construct($component = null)
	{
		parent::__construct($component);
		$this->errors = new ErrorCollection();
	}

	public function onPrepareComponentParams($arParams): array
	{
		$arParams['MANIFEST_CODE'] ??= '';
		$arParams['ITEM_CODE'] ??= '';
		$arParams['ADDITIONAL'] ??= []; // data from Request
		$arParams['CONTEXT_USER'] ??= null; // not set outside

		return parent::onPrepareComponentParams($arParams);
	}

	protected function checkRequiredParams()
	{
		$access = Manifest::checkAccess(Manifest::ACCESS_TYPE_EXPORT, $this->arParams['MANIFEST_CODE']);
		if ($access['result'] !== true)
		{
			$this->errors->setError(
				new Error(
					$access['message'] !== ''
						? htmlspecialcharsbx($access['message'])
						: Loc::getMessage('REST_CONFIGURATION_EXPORT_ACCESS_DENIED')
				)
			);

			return false;
		}

		if(empty($this->arParams['MANIFEST_CODE']))
		{
			$this->errors->setError(new Error(Loc::getMessage("REST_CONFIGURATION_EXPORT_MANIFEST_EMPTY")));
			return false;
		}
		return true;
	}

	protected function listKeysSignedParameters()
	{
		return [
			'MANIFEST_CODE',
			'ITEM_CODE',
			'ADDITIONAL',
		];
	}

	protected function prepareResult()
	{
		$result = [];
		global $APPLICATION;

		$manifest = Manifest::get($this->arParams['MANIFEST_CODE']);
		if(is_null($manifest))
		{
			$this->errors->setError(new Error(Loc::getMessage("REST_CONFIGURATION_EXPORT_NOT_FOUND")));
			return false;
		}
		$result['MANIFEST'] = $manifest;

		$APPLICATION->SetTitle( $result['MANIFEST']['EXPORT_TITLE_PAGE'] ?: Loc::getMessage('REST_CONFIGURATION_EXPORT_TITLE') );

		$result['ENABLED_ZIP_MODE'] = Helper::getInstance()->enabledZipMod();
		$result['ENABLED_EXPORT'] = $result['ENABLED_ZIP_MODE'];
		if($result['ENABLED_ZIP_MODE'] != 'Y')
		{
			$result['REST_SETTING_PATH'] = BX_ROOT.'/admin/settings.php?lang='.LANGUAGE_ID.'&mid=rest';
		}

		$this->arResult = $result;
		return true;
	}

	protected function printErrors()
	{
		foreach ($this->errors as $error)
		{
			ShowError($error);
		}
	}

	public function executeComponent()
	{
		$this->errors = new ErrorCollection();

		if (!$this->checkRequiredParams())
		{
			$this->printErrors();
			return;
		}

		if (!$this->prepareResult())
		{
			$this->printErrors();
			return;
		}

		$this->includeComponentTemplate();
	}

	protected function getContextPostFix()
	{
		return $this->contextPostfix.$this->arParams['MANIFEST_CODE'];
	}

	protected function getUserContext()
	{
		return $this->arParams['CONTEXT_USER'] ?? Helper::getInstance()->getContextUser($this->getContextPostFix());
	}

	public function startAction()
	{
		$result = [];
		if($this->checkRequiredParams())
		{
			$userContext = $this->getUserContext();

			$setting = new Setting($userContext);
			$setting->deleteFull();
			$setting->set(Setting::MANIFEST_CODE, $this->arParams['MANIFEST_CODE']);
			$setting->set(Setting::SETTING_ACTION_ADDITIONAL_OPTION, $this->arParams['ADDITIONAL'] ?? []);

			$structure = new Structure($userContext);
			if($structure->getFolder())
			{
				$result = Controller::getEntityCodeList();
			}
		}

		return $result;
	}

	public function finishAction()
	{
		$result = [
			'result' => false,
		];
		if ($this->checkRequiredParams())
		{
			$userContext = $this->getUserContext();
			$setting = new Setting($userContext);
			$info = $setting->get(Setting::SETTING_FINISH_DATA);
			if ($info === null)
			{
				$info = [
					'STEP' => 0,
					'NEXT' => 0,
				];
			}
			if ($info['STEP'] === 0)
			{
				$info['NEXT'] = $this->doLatestEvent();
			}
			elseif ($info['STEP'] === 1)
			{
				$info['NEXT'] = $this->cureArchiveFiles($info['NEXT']);
			}
			elseif ($info['STEP'] === 2)
			{
				$info['NEXT'] = $this->cureArchiveConfig($info['NEXT']);
			}

			if (!is_int($info['NEXT']))
			{
				$info['STEP']++;
				$info['NEXT'] = 0;
			}

			if ($info['STEP'] > 2)
			{
				$result['result'] = true;
				$result['download'] = $this->getDownloadUrl();
			}

			$setting->set(Setting::SETTING_FINISH_DATA, $info);
		}

		return $result;
	}

	private function cureArchiveFiles(int $next)
	{
		$result = false;

		$userContext = $this->getUserContext();
		$structure = new Structure($userContext);
		$fileList = $structure->getFileList();

		if (empty($fileList))
		{
			return $result;
		}

		$list = [];
		foreach ($fileList as $key => $file)
		{
			if ((int)$key > $next)
			{
				$file['TMP_ID'] = (int)$key;
				$list[$file['ID']] = $file;
			}
		}

		if (empty($list))
		{
			return $result;
		}

		$res = FileTable::getList(
			[
				'order' => [
					'ID' => 'ASC',
				],
				'filter' => [
					'=ID' => array_keys($list),
					'=FILE_SIZE' => range(1444, 1460),
				],
				'select' => [
					'ID',
					'ORIGINAL_NAME',
					'MODULE_ID',
				],
				'limit' => 10,
			]
		);

		$documentRoot = Application::getDocumentRoot();

		$deleteIdList = [];
		while ($item = $res->fetch())
		{
			$saveId = $list[$item['ID']]['TMP_ID'];
			unset($list[$item['ID']]['TMP_ID']);
			$filePath = $documentRoot . CFile::GetPath($item['ID']);
			if (File::isFileExists($filePath))
			{
				$content = File::getFileContents($filePath);
				$structure->addSmallFile($list[$item['ID']], $content);
				$deleteIdList[] = $saveId;
			}
			$result = $saveId;
		}

		if (!empty($deleteIdList))
		{
			StorageTable::deleteByFilter(
				[
					'=ID' => $deleteIdList,
				]
			);
		}

		return $result;
	}

	private function cureArchiveConfig($next)
	{
		$result = false;

		$res = FileTable::getList(
			[
				'order' => [
					'ID' => 'ASC',
				],
				'filter' => [
					'>ID' => $next,
					'=MODULE_ID' => 'rest',
					'=FILE_SIZE' => range(1444, 1460),
					'=CONTENT_TYPE' => 'application/octet-stream',
				],
				'select' => [
					'ID',
					'ORIGINAL_NAME',
					'MODULE_ID',
				],
				'limit' => 10,
			]
		);

		$httpClient = new HttpClient();
		while ($file = $res->fetch())
		{
			$result = (int)$file['ID'];
			$path = CFile::GetPath($file['ID']);

			try
			{
				if (mb_strpos($path, 'https://') === 0)
				{
					$content = $httpClient->get($path);
				}
				else
				{
					$content = file_get_contents($path);
				}
				$data = Json::decode($content);
				$data['TMP_HASH'] = Random::getString(128);

				$newId = CFile::SaveFile(
					[
						'name' => $file['ORIGINAL_NAME'],
						'MODULE_ID' => 'rest',
						'content' => Json::encode($data),
						'description' => 'configuration_delete',
					],
					'configuration/export'
				);

				$resStore = StorageTable::getList(
					[
						'filter' => [
							'=CODE' => 'CONFIGURATION_FILES_LIST',
							'DATA' => '%"ID":' . $file['ID'] . ',%',
						],
						'select' => [
							'ID',
							'DATA',
						],
					]
				);

				while ($store = $resStore->fetch())
				{
					$store['DATA']['ID'] = $newId;
					StorageTable::update(
						$store['ID'],
						[
							'DATA' => $store['DATA']
						]
					);
				}
				CFile::Delete($file['ID']);
			}
			catch (\Exception $e)
			{
			}
		}

		return $result;
	}

	private function doLatestEvent()
	{
		$result = false;
		$manifest = Manifest::get($this->arParams['MANIFEST_CODE']);
		if (!is_null($manifest))
		{
			$manifest = [
				'CODE' => $manifest['CODE'],
				'VERSION' => Setting::VERSION,
				'MANIFEST_VERSION' => $manifest['VERSION'],
				'USES' => $manifest['USES'],
				'COMPATIBILITY_TAGS' => $manifest['COMPATIBILITY_TAGS'] ?? [],
				'METADATA' => $manifest['METADATA'] ?? [],
			];
			$userContext = $this->getUserContext();

			$setting = new Setting($userContext);

			Controller::callEventFinish(
				[
					'TYPE' => 'EXPORT',
					'CONTEXT' => $this->getContextPostFix(),
					'CONTEXT_USER' => $userContext,
					'USER_ID' => $setting->get(Setting::SETTING_USER_ID) ?? 0,
					'MANIFEST_CODE' => $manifest['CODE'],
					'IMPORT_MANIFEST' => [],//TODO: delete this after fix crm
					'MANIFEST' => &$manifest,
					'ITEM_CODE' => $this->arParams['ITEM_CODE'] ?? '',
					'SETTING' => $setting->get(Setting::SETTING_MANIFEST),
					'ADDITIONAL_OPTION' => $setting->get(Setting::SETTING_ACTION_ADDITIONAL_OPTION),
				]
			);
			(new Structure($userContext))->saveContent(false, 'manifest', $manifest);

			$result = true;

			$setting->delete(Setting::SETTING_MANIFEST);
		}

		return $result;
	}

	private function getDownloadUrl()
	{
		$uri = UrlManager::getInstance()->getEndPoint();
		$uri->addParams(
			[
				'action' => 'rest.controller.configuration.download',
				'postfix' => $this->getContextPostFix()
			]
		);

		return $uri->getUri();
	}

	public function loadAction(?string $code = null, ?int $step = null, ?string $next = null)
	{
		$result = [];

		if($this->checkRequiredParams())
		{
			$code = preg_replace('/[^a-zA-Z0-9_]/', '', $code ?? $this->request->getPost('code'));
			$step = $step ?? intval($this->request->getPost('step'));
			$next = $next ?? htmlspecialcharsbx($this->request->getPost('next'));
			if ($code)
			{
				$structure = new Structure($this->getUserContext());
				$items = Controller::callEventExport(
					$this->arParams['MANIFEST_CODE'],
					$code,
					$step,
					$next,
					$this->arParams['ITEM_CODE'] ?? '',
					$this->getUserContext()
				);
				foreach ($items as $item)
				{
					$fileName = !is_array($item['FILE_NAME']) ? (string) $item['FILE_NAME'] : '';
					if ($fileName <> '')
					{
						$saveResult = $structure->saveContent($code, $fileName, $item['CONTENT']);
						if (is_array($saveResult))
						{
							foreach ($saveResult as $error)
							{
								$item['ERROR_EXCEPTION'][] = $error;
							}
						}
					}

					if ($item['ERROR_EXCEPTION'])
					{
						$result['exception'] = $item['ERROR_EXCEPTION'];
					}
					if ($item['ERROR_MESSAGES'])
					{
						$result['errors'][] = $item['ERROR_MESSAGES'];
					}
					if ($item['ERROR_ACTION'])
					{
						$result['errorsNotice'][] = $item['ERROR_ACTION'];
					}
					if (isset($item['FILES']) && is_array($item['FILES']))
					{
						$fileList = $structure->getFileList() ?? [];
						$existingFileIds = array_map('intval', array_column($fileList, 'ID'));
						foreach ($item['FILES'] as $file)
						{
							$fileId = isset($file['ID']) ? (int)$file['ID'] : 0;

							if ($fileId === 0)
							{
								$structure->saveFile($file['ID'], $file);
								continue;
							}

							if (!in_array($fileId, $existingFileIds, true))
							{
								$structure->saveFile($fileId, $file);
								$existingFileIds[] = $fileId;
							}
						}
					}

					$result['next'] = $item['NEXT'];
				}
			}
		}

		if(!isset($result['next']))
		{
			$result['next'] = false;
		}

		return $result;
	}

	public function loadManifestAction()
	{
		$result = [
			'next' => false
		];

		if($this->checkRequiredParams())
		{
			$step = intval($this->request->getPost("step"));
			$next = htmlspecialcharsbx($this->request->getPost("next"));
			$items = Manifest::callEventInit(
				$this->arParams['MANIFEST_CODE'],
				[
					'TYPE' => 'EXPORT',
					'STEP' => $step,
					'NEXT' => $next,
					'ITEM_CODE' => $this->arParams['ITEM_CODE'] ?? '',
					'CONTEXT_USER' => $this->getUserContext(),
					'ADDITIONAL_OPTION' => $this->arParams['ADDITIONAL'] ?? [],
				]
			);
			foreach ($items as $item)
			{
				if ($item['ERROR_MESSAGES'])
				{
					$result['errors'][] = $item['ERROR_MESSAGES'];
				}
				if ($item['ERROR_ACTION'])
				{
					$result['errorsNotice'][] = $item['ERROR_ACTION'];
				}

				$result['next'] = $item['NEXT'];
			}
		}
		return $result;
	}

	public function configureActions()
	{
		return [
			'start' => [
				'prefilters' => [
					new ActionFilter\Authentication(),
					new ActionFilter\HttpMethod(
						[ActionFilter\HttpMethod::METHOD_POST]
					),
					new ActionFilter\Csrf(),
				],
				'postfilters' => [

				]
			],
			'load' => [
				'prefilters' => [
					new ActionFilter\Authentication(),
					new ActionFilter\HttpMethod(
						[ActionFilter\HttpMethod::METHOD_POST]
					),
					new ActionFilter\Csrf()
				],
				'postfilters' => [

				]
			],
			'finish' => [
				'prefilters' => [
					new ActionFilter\Authentication(),
					new ActionFilter\HttpMethod(
						[ActionFilter\HttpMethod::METHOD_POST]
					),
					new ActionFilter\Csrf()
				],
				'postfilters' => [

				]
			],
			'loadManifest' => [
				'prefilters' => [
					new ActionFilter\Authentication(),
					new ActionFilter\HttpMethod(
						[ActionFilter\HttpMethod::METHOD_POST]
					),
					new ActionFilter\Csrf()
				]
			]
		];
	}
}
