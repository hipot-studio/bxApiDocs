<?php

/** @noinspection PhpUnusedParameterInspection */

namespace Bitrix\DocumentGenerator\Controller;

use Bitrix\DocumentGenerator\Body;
use Bitrix\DocumentGenerator\Body\Docx;
use Bitrix\DocumentGenerator\DataProvider\Rest;
use Bitrix\DocumentGenerator\DataProviderManager;
use Bitrix\DocumentGenerator\Driver;
use Bitrix\DocumentGenerator\Engine\CheckPermissions;
use Bitrix\DocumentGenerator\Feature\DocumentTitlePattern;
use Bitrix\DocumentGenerator\Model\DocumentTable;
use Bitrix\DocumentGenerator\Model\FileTable;
use Bitrix\DocumentGenerator\Model\TemplateProviderTable;
use Bitrix\DocumentGenerator\Model\TemplateTable;
use Bitrix\DocumentGenerator\Model\TemplateUserTable;
use Bitrix\DocumentGenerator\TitlePattern;
use Bitrix\DocumentGenerator\Timezone;
use Bitrix\DocumentGenerator\UserPermissions;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\ActionFilter\Scope;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Engine\Response\DataType\ContentUri;
use Bitrix\Main\Engine\Response\DataType\Page;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\Path;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Numerator\Model\NumeratorTable;
use Bitrix\Main\Numerator\Numerator;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UI\PageNavigation;

class Template extends Base
{
	const DEFAULT_DATA_PATH = '/bitrix/modules/documentgenerator/data/';

	private const TITLE_PATTERN_LOGGER_ID = 'documentgenerator.Default';

	/**
	 * @return array
	 */
	public function getDefaultPreFilters()
	{
		$preFilters = parent::getDefaultPreFilters();
		$preFilters[] = new CheckPermissions(UserPermissions::ENTITY_TEMPLATES);

		return $preFilters;
	}

	/**
	 * @return array
	 */
	public function configureActions()
	{
		$configureActions = parent::configureActions();
		$configureActions['download'] = [
			'-prefilters' => [
				Csrf::class
			]
		];
		$configureActions['getTitlePatternData'] = [
			'-prefilters' => [
				CheckPermissions::class,
			],
			'+prefilters' => [
				new Scope(Scope::AJAX),
				new HttpMethod([HttpMethod::METHOD_POST]),
			],
		];

		return $configureActions;
	}

	/**
	 * Deletes template by id.
	 *
	 * @param \Bitrix\DocumentGenerator\Template $template
	 * @param \CRestServer|null $restServer
	 * @throws \Exception
	 */
	public function deleteAction(\Bitrix\DocumentGenerator\Template $template, \CRestServer $restServer = null)
	{
		$deleteResult = TemplateTable::delete($template->ID);
		if (!$deleteResult->isSuccess())
		{
			$this->errorCollection = $deleteResult->getErrorCollection();
		}
	}

	/**
	 * Let user download template file.
	 *
	 * @param \Bitrix\DocumentGenerator\Template $template
	 * @param \CRestServer|null $restServer
	 * @return array|null
	 */
	public function downloadAction(\Bitrix\DocumentGenerator\Template $template, \CRestServer $restServer = null)
	{
		Loc::loadLanguageFile(__FILE__);
		if(FileTable::download($template->FILE_ID, $template->getFileName(Loc::getMessage('DOCGEN_CONTROLLER_TEMPLATE_FILE_PREFIX'))) === false)
		{
			$this->errorCollection->add([new Error(Loc::getMessage('DOCGEN_CONTROLLER_TEMPLATE_DOWNLOAD_ERROR'))]);
		}

		return null;
	}

	/**
	 * Add new template.
	 *
	 * @param array $fields
	 * @param \CRestServer|null $restServer
	 * @return array|null
	 * @throws \Bitrix\Main\ObjectException
	 */
	public function addAction(array $fields, \CRestServer $restServer = null)
	{
		$this->prepareTitlePatternInput($fields);
		$this->prepareTimezoneInput($fields);
		if (!$this->validateTimezoneInput($fields))
		{
			return null;
		}

		if($restServer && !isset($fields['fileId']))
		{
			$fields['fileId'] = $this->uploadFile($fields[static::FILE_PARAM_NAME], [
				'isTemplate' => true,
			]);
			if(!$fields['fileId'])
			{
				return null;
			}
			unset($fields[static::FILE_PARAM_NAME]);
		}
		// do not let add templates to other modules in rest scope
		if($restServer)
		{
			$fields['moduleId'] = Driver::REST_MODULE_ID;
			$fields['providers'] = [
				Rest::class,
			];
		}
		if(empty($fields['providers']))
		{
			unset($fields['providers']);
		}
		elseif(!is_array($fields['providers']))
		{
			$fields['providers'] = [$fields['providers']];
		}
		$emptyFields = $this->checkArrayRequiredParams($fields, ['name', 'fileId', 'numeratorId', 'region', 'providers', 'moduleId']);
		if(!empty($emptyFields))
		{
			$this->errorCollection[] = new Error('Empty required fields: '.implode(', ', $emptyFields));
			return null;
		}

		if(!$this->includeModule($fields['moduleId']))
		{
			return null;
		}
		if (!$this->validateTitlePattern($fields))
		{
			return null;
		}
		if(!$fields['active'])
		{
			$fields['active'] = 'Y';
		}
		if(!$fields['withStamps'])
		{
			$fields['withStamps'] = 'N';
		}
		if(!$fields['users'])
		{
			$fields['users'] = [];
		}
		if(empty($fields['users']))
		{
			$currentUserId = Driver::getInstance()->getUserId();
			if($currentUserId > 0)
			{
				$fields['users'][] = 'U' . $currentUserId;
			}
		}
		$fields['bodyType'] = Docx::class;
		$fields['isDefault'] = 'N';
		$fields['createdBy'] = Driver::getInstance()->getUserId();
		$converter = new Converter(Converter::TO_UPPER | Converter::KEYS | Converter::TO_SNAKE);
		$templateData = $converter->process($fields);
		$result = $this->add($templateData, $fields['providers'], $fields['users'], true, $restServer);
		if ($result->isSuccess())
		{
			foreach ($fields['providers'] as $provider)
			{
				Driver::extendTemplateProviders($fields['moduleId'], $provider);
			}

			$templateId = $result->getId();
			if ($templateId > 0 && !empty($fields['customFields']) )
			{
				EventManager::getInstance()->send(
					new Event(
						Driver::MODULE_ID,
						'onModifyCustomFields',
						[
							'templateId' =>$templateId,
							'customFields' => $fields['customFields'],
						]
					)
				);
			}

			return $result->getData();
		}

		$this->errorCollection = $result->getErrorCollection();

		return null;
	}

	/**
	 * @param \Bitrix\DocumentGenerator\Template $template
	 * @param array $fields
	 * @param \CRestServer|null $restServer
	 * @return array|null
	 * @throws \Bitrix\Main\ObjectException
	 */
	public function updateAction(\Bitrix\DocumentGenerator\Template $template, array $fields, \CRestServer $restServer = null)
	{
		$this->prepareTitlePatternInput($fields);
		$this->prepareTimezoneInput($fields);
		if (!$this->validateTimezoneInput($fields))
		{
			return null;
		}

		// do not let change moduleId in rest scope
		if($restServer)
		{
			unset($fields['moduleId']);
			$fileId = $this->uploadFile($fields[static::FILE_PARAM_NAME], [
				'required' => false,
				'isTemplate' => true,
			]);
			if($fileId > 0)
			{
				$fields['fileId'] = $fileId;
			}
			elseif(isset($fields['fileId']))
			{
				unset($fields['fileId']);
			}
			unset($fields[static::FILE_PARAM_NAME]);
		}
		elseif(!$this->includeModule($fields['moduleId']))
		{
			return null;
		}
		$fields['bodyType'] = Docx::class;
		$fields['id'] = $template->ID;
		if (
			Feature::isEnabled(DocumentTitlePattern::class)
			&& !array_key_exists('titlePattern', $fields)
			&& $template->TITLE_PATTERN !== null
			&& (array_key_exists('fileId', $fields) || array_key_exists('providers', $fields))
		)
		{
			$fields['titlePattern'] = $template->TITLE_PATTERN;
		}
		if (!$this->validateTitlePattern($fields, $template))
		{
			return null;
		}
		$converter = new Converter(Converter::TO_UPPER | Converter::KEYS | Converter::TO_SNAKE);
		$templateData = $converter->process($fields);
		if(!isset($fields['users']) || !is_array($fields['users']))
		{
			$fields['users'] = [];
		}
		if(!isset($fields['providers']) || !is_array($fields['providers']))
		{
			$fields['providers'] = [];
		}

		if (
			isset($templateData['FILE_ID'])
			&& is_numeric($templateData['FILE_ID'])
			&& (int)$templateData['FILE_ID'] > 0
			&& is_numeric($template->FILE_ID)
			&& (int)$template->FILE_ID > 0
			&& (int)$templateData['FILE_ID'] !== (int)$template->FILE_ID
		)
		{
			// even if the template was default previously, it won't be default anymore, since we want to change its body
			$templateData['IS_DEFAULT'] = 'N';
		}

		$result = $this->add($templateData, $fields['providers'], $fields['users'], true, $restServer);
		if ($result->isSuccess())
		{
			if (!empty($fields['providers']))
			{
				$moduleId = $result->getData()['template']['moduleId'];
				foreach ($fields['providers'] as $provider)
				{
					Driver::extendTemplateProviders($moduleId, $provider);
				}
			}

			if (!empty($fields['customFields']))
			{
				EventManager::getInstance()->send(
					new Event(
						Driver::MODULE_ID,
						'onModifyCustomFields',
						[
							'templateId' => $template->ID,
							'customFields' => $fields['customFields'],
						]
					)
				);
			}

			return $result->getData();
		}

		$this->errorCollection = $result->getErrorCollection();

		return null;
	}

	/**
	 * @return bool
	 */
	public function isSupportArrayInCreateActions()
	{
		return true;
	}

	/**
	 * Install default template with code $code. If template with the same code is installed - it will be overwritten.
	 *
	 * @param string $code
	 * @return array|bool
	 */
	public function installDefaultAction($code)
	{
		if($this->getScope() === static::SCOPE_REST)
		{
			$this->errorCollection->add([new Error('Wrong scope for current action')]);
			return null;
		}
		$filter = ['CODE' => $code];
		$result = static::getDefaultTemplateList($filter);
		if($result->isSuccess())
		{
			$templates = $result->getData();
			if(!isset($templates[$code]))
			{
				$this->errorCollection->add([new Error(Loc::getMessage('DOCGEN_TEMPLATES_DEFAULT_TEMPLATE_NOT_FOUND'))]);
				return null;
			}
			$template = $templates[$code];

			$result = $this->installDefaultTemplate($template);
		}
		if(!$result->isSuccess())
		{
			$this->errorCollection = $result->getErrorCollection();
		}

		return $result->getData();
	}

	/**
	 * Install default template.
	 *
	 * @param array $template
	 * @param bool $checkPermissions
	 *
	 * @return Result
	 */
	public function installDefaultTemplate(array $template, bool $checkPermissions = true)
	{
		$result = new Result();
		if (!isset($template['BODY_TYPE']) || !is_a($template['BODY_TYPE'], Body::class, true))
		{
			return $result->addError(new Error('Template body type is invalid'));
		}

		/** @var Body $body */
		$body = new $template['BODY_TYPE']('');
		$bodyFile = new \Bitrix\Main\IO\File(Path::combine(Application::getDocumentRoot(), $template['FILE']));
		if($bodyFile->isExists())
		{
			$fileArray = \CFile::MakeFileArray($bodyFile->getPath(), $body->getFileMimeType());
			$fileArray['isTemplate'] = true;
			$saveResult = FileTable::saveFile($fileArray);
			if($saveResult->isSuccess())
			{
				$template['FILE_ID'] = $saveResult->getId();
			}
			else
			{
				$result->addErrors($saveResult->getErrors());
			}
		}
		else
		{
			$result->addError(new Error('File '.$bodyFile->getPath().' is not exist'));
		}
		if($result->isSuccess())
		{
			if(isset($template['MODULE_ID']) && !$this->includeModule($template['MODULE_ID']))
			{
				$result->addErrors($this->getErrors());
			}
		}
		if ($result->isSuccess() && isset($template['NUMERATOR']) && !isset($template['NUMERATOR_ID']))
		{
			$config = $template['NUMERATOR']['CONFIG'];
			$config[Numerator::getType()]['type'] = Driver::NUMERATOR_TYPE;

			if (!empty($config[Numerator::getType()]['code']))
			{
				// in case the numerator was created on this hit while adding another default template
				$id = NumeratorTable::getIdByCode($config[Numerator::getType()]['code']);
				if ($id !== null)
				{
					$template['NUMERATOR_ID'] = $id;
				}
			}

			if (empty($template['NUMERATOR_ID']))
			{
				$numerator = Numerator::create();
				$numeratorCreateResult = $numerator->setConfig($config);
				if ($numeratorCreateResult->isSuccess())
				{
					$numeratorCreateResult = $numerator->save();
				}

				if ($numeratorCreateResult->isSuccess())
				{
					$template['NUMERATOR_ID'] = $numerator->getId();
				}
				else
				{
					$result->addErrors($numeratorCreateResult->getErrors());
				}
			}
		}
		if($result->isSuccess())
		{
			if(isset($template['IS_DELETED']) && $template['IS_DELETED'] === 'Y')
			{
				unset($template['ID']);
			}
			$template['IS_DELETED'] = 'N';
			$template['IS_DEFAULT'] = 'Y';
			$providers = $template['PROVIDERS'];
			unset($template['PROVIDER_NAMES']);
			unset($template['PROVIDERS']);
			unset($template['FILE']);
			if (!empty($template['ID']))
			{
				unset($template['PRODUCTS_TABLE_VARIANT']);
			}

			$result = $this->add($template, $providers, [TemplateUserTable::ALL_USERS], $checkPermissions);
		}

		return $result;
	}

	/**
	 * @internal
	 */
	final public function updateBodyOfDefaultTemplate(array $defaultTemplateDescription, bool $checkPermissions = true): Result
	{
		$result = new Result();

		$id = (int)($defaultTemplateDescription['ID'] ?? 0);
		if ($id <= 0)
		{
			return $result->addError(new Error('ID not found'));
		}

		$oldTemplate = TemplateTable::query()->setSelect(['IS_DEFAULT'])->where('ID', $id)->fetchObject();
		if ($oldTemplate?->getIsDefault() !== true)
		{
			return $result->addError(new Error('Template not found or its not default'));
		}

		if (
			!isset($defaultTemplateDescription['BODY_TYPE'])
			|| !is_a($defaultTemplateDescription['BODY_TYPE'], Body::class, true)
		)
		{
			return $result->addError(new Error('Template body type is invalid'));
		}

		/** @var Body $body */
		$body = new $defaultTemplateDescription['BODY_TYPE']('');
		$bodyFile = new \Bitrix\Main\IO\File(Path::combine(Application::getDocumentRoot(), $defaultTemplateDescription['FILE']));
		if (!$bodyFile->isExists() || !$bodyFile->isReadable())
		{
			return $result->addError(new Error('File '.$bodyFile->getPath().' is not exist'));
		}

		$fileArray = \CFile::MakeFileArray($bodyFile->getPath(), $body->getFileMimeType());
		$fileArray['isTemplate'] = true;

		$fileSaveResult = FileTable::saveFile($fileArray);
		if (!$fileSaveResult->isSuccess())
		{
			return $fileSaveResult;
		}

		return $this->add(['ID' => $id, 'FILE_ID' => $fileSaveResult->getId()], [], [], $checkPermissions);
	}

	/**
	 * Returns list of default templates.
	 *
	 * @param array $filter
	 * @return Result
	 */
	public static function getDefaultTemplateList(array $filter = [])
	{
		$result = new Result();
		$dataPath = Application::getDocumentRoot().self::DEFAULT_DATA_PATH;

		if(!Directory::isDirectoryExists($dataPath))
		{
			return $result->addError(new Error('Default data directory not found'));
		}
		$templatesFile = new \Bitrix\Main\IO\File(Path::combine($dataPath, 'templates.php'));
		if(!$templatesFile->isExists())
		{
			return $result->addError(new Error('File with default templates not found'));
		}
		$templates = include $templatesFile->getPath();
		if(!is_array($templates))
		{
			return $result->addError(new Error('No data in templates file'));
		}

		foreach($templates as $key => $template)
		{
			if(!$template['FILE'])
			{
				$result->addError(new Error('Empty FILE for template'));
				unset($templates[$key]);
				continue;
			}
			if(isset($filter['CODE']) && $template['CODE'] != $filter['CODE'])
			{
				unset($templates[$key]);
				continue;
			}
			if(isset($filter['MODULE_ID']) && $template['MODULE_ID'] != $filter['MODULE_ID'])
			{
				unset($templates[$key]);
				continue;
			}
			if(isset($filter['REGION']))
			{
				if(is_array($filter['REGION']))
				{
					if(!in_array($template['REGION'], $filter['REGION']))
					{
						unset($templates[$key]);
						continue;
					}
				}
				else
				{
					if($filter['REGION'] != $template['REGION'])
					{
						unset($templates[$key]);
						continue;
					}
				}
			}
			if(isset($filter['NAME']) && mb_strpos($template['NAME'], $filter['NAME']) === false)
			{
				unset($templates[$key]);
				continue;
			}
		}

		$templates = array_values($templates);

		$availableProviders = DataProviderManager::getInstance()->getList();
		$extendedProviders = [];
		foreach($availableProviders as $singleAvailableProvider)
		{
			if(isset($singleAvailableProvider['ORIGINAL']))
			{
				$extendedProviders[$singleAvailableProvider['ORIGINAL']][] = $singleAvailableProvider;
			}
		}
		$buffer = $names = $codes = [];
		foreach($templates as $template)
		{
			$names[] = $template['NAME'];
			$codes[] = $template['CODE'];
			foreach($template['PROVIDERS'] as $key => $provider)
			{
				$provider = mb_strtolower($provider);
				if(isset($extendedProviders[$provider]))
				{
					unset($template['PROVIDERS'][$key]);
					foreach($extendedProviders[$provider] as $extendedProvider)
					{
						$template['PROVIDER_NAMES'][] = $extendedProvider['NAME'];
						$template['PROVIDERS'][] = $extendedProvider['CLASS'];
					}
				}
				else
				{
					$template['PROVIDER_NAMES'][] = $availableProviders[mb_strtolower($provider)]['NAME'];
				}
			}
			$buffer[$template['CODE']] = $template;
		}
		$templates = $buffer;
		unset($buffer);
		$oldTemplates = TemplateTable::getList([
			'select' => [
				'ID',
				'FILE_ID',
				'NAME',
				'CODE',
				'IS_DELETED',
				'IS_DEFAULT',
			],
			'order' => [
				'ID' => 'desc'
			],
			'filter' => [
				'@CODE' => $codes,
				'@NAME' => $names,
			],
		])->fetchAll();
		$unFoundTemplates = $templates;
		foreach($oldTemplates as $oldTemplate)
		{
			foreach($unFoundTemplates as $code => $unFoundTemplate)
			{
				if($oldTemplate['CODE'] == $unFoundTemplate['CODE'] && $oldTemplate['NAME'] == $unFoundTemplate['NAME'])
				{
					$templates[$code]['IS_DELETED'] = $oldTemplate['IS_DELETED'];
					$templates[$code]['IS_DEFAULT'] = $oldTemplate['IS_DEFAULT'];
					$templates[$code]['ID'] = $oldTemplate['ID'];
					$templates[$code]['FILE_ID'] = $oldTemplate['FILE_ID'];
					unset($unFoundTemplates[$code]);
					break;
				}
			}
		}

		$numeratorCodes = [];
		foreach ($templates as $template)
		{
			$baseNumeratorConfig = $template['NUMERATOR']['CONFIG'][Numerator::getType()] ?? null;
			if ($baseNumeratorConfig && isset($baseNumeratorConfig['code']))
			{
				$numeratorCodes[$baseNumeratorConfig['code']] = (string)$baseNumeratorConfig['code'];
			}
		}

		if (!empty($numeratorCodes))
		{
			$dbIterator =
				NumeratorTable::query()
					->setSelect(['ID', 'CODE'])
					->whereIn('CODE', $numeratorCodes)
					->exec()
			;

			$codeToIdMap = [];
			while ($numerator = $dbIterator->fetchObject())
			{
				$codeToIdMap[$numerator->getCode()] = $numerator->getId();
			}

			foreach ($templates as &$template)
			{
				$code = $template['NUMERATOR']['CONFIG'][Numerator::getType()]['code'] ?? null;
				if ($code && isset($codeToIdMap[$code]))
				{
					$template['NUMERATOR_ID'] = $codeToIdMap[$code];
				}
			}
			unset($template);
		}

		$result->setData($templates);

		return $result;
	}

	/**
	 * @param array $templateData
	 * @param array $providers
	 * @param array $users
	 * @return Result
	 * @throws \Bitrix\Main\ObjectException
	 * @throws \Exception
	 */
	protected function add(
		array $templateData,
		array $providers = [],
		array $users = [],
		bool $checkPermissions = true,
		?\CRestServer $restServer = null,
	)
	{
		$result = new Result();
		$id = intval($templateData['ID'] ?? 0);
		if($id > 0 && $checkPermissions && !Driver::getInstance()->getUserPermissions()->canModifyTemplate($id))
		{
			$result->addError(new Error('You do not have permissions to modify this template'));
		}
		if($result->isSuccess())
		{
			if($id > 0)
			{
				$template = \Bitrix\DocumentGenerator\Template::loadById($id);
				if($template)
				{
					unset($templateData['CREATED_BY']);
					unset($templateData['ID']);
					$templateData['UPDATE_TIME'] = new DateTime();
					$templateData['UPDATED_BY'] = Driver::getInstance()->getUserId();
					$result = TemplateTable::update($id, $templateData);
				}
				else
				{
					$result->addError(new Error(Loc::getMessage('DOCGEN_CONTROLLER_TEMPLATE_NOT_FOUND')));
				}
			}
			else
			{
				if(empty($templateData['NUMERATOR_ID']))
				{
					$templateData['NUMERATOR_ID'] = $this->createNumerator($templateData['NAME']);
				}
				$result = TemplateTable::add($templateData);
			}
		}
		if(!$result->isSuccess())
		{
			return $result;
		}
		$templateId = $result->getId();
		if(!empty($providers))
		{
			TemplateProviderTable::deleteByTemplateId($templateId);
			foreach($providers as $provider)
			{
				if (
					DataProviderManager::checkProviderName(
						TemplateProviderTable::getClassNameFromFilterString($provider),
						$templateData['MODULE_ID']
					)
				)
				{
					$providerResult = TemplateProviderTable::add([
						'TEMPLATE_ID' => $templateId,
						'PROVIDER' => $provider,
					]);
					if(!$providerResult->isSuccess())
					{
						$result->addErrors($providerResult->getErrors());
					}
				}
			}
		}

		$users = $this->filterAccessCodes($users);
		if (!empty($users))
		{
			TemplateUserTable::deleteByTemplateIds([$templateId]);

			foreach($users as $code)
			{
				$userResult = TemplateUserTable::add([
					'TEMPLATE_ID' => $templateId,
					'ACCESS_CODE' => $code,
				]);

				if(!$userResult->isSuccess())
				{
					$result->addErrors($userResult->getErrors());
				}
			}
		}

		$template = \Bitrix\DocumentGenerator\Template::loadById($templateId);
		$result->setData($this->getAction($template, $restServer));

		return $result;
	}

	private function filterAccessCodes(array $users): array
	{
		$accessCodes = [];

		foreach ($users as $code)
		{
			if (!is_string($code))
			{
				continue;
			}

			$code = trim($code);
			if ($code !== '')
			{
				$accessCodes[] = $code;
			}
		}

		return array_values(array_unique($accessCodes));
	}

	/**
	 * @param string $name
	 * @return int|null
	 */
	protected function createNumerator($name)
	{
		$numeratorId = null;

		$numerator = Numerator::create();
		$numerator->setConfig([
			Numerator::getType() => [
				'name'     => $name,
				'template' => '{NUMBER}',
				'type'     => Driver::NUMERATOR_TYPE,
			],
		]);
		$saveResult = $numerator->save();
		if($saveResult->isSuccess())
		{
			$numeratorId = $saveResult->getId();
		}

		return $numeratorId;
	}

	protected function includeModule($moduleId)
	{
		if(!empty($moduleId) && !(ModuleManager::isModuleInstalled($moduleId) && Loader::includeModule($moduleId)))
		{
			$this->errorCollection->add([new Error(Loc::getMessage('DOCGEN_CONTROLLER_MODULE_INVALID', ['#MODULE#' => $moduleId]))]);
			return false;
		}

		return true;
	}

	/**
	 * @param \Bitrix\DocumentGenerator\Template $template
	 * @param $providerClassName
	 * @param $value
	 * @param array $values
	 * @param \CRestServer|null $restServer
	 * @return array|null
	 */
	public function getFieldsAction(\Bitrix\DocumentGenerator\Template $template, $providerClassName = null, $value = null, array $values = [], \CRestServer $restServer = null)
	{
		if($restServer)
		{
			$providerClassName = Rest::class;
			$value = 1;
		}
		$template->setSourceType($providerClassName);
		if($template->isDeleted())
		{
			$this->errorCollection[] = new Error('Cannot get fields from deleted template');
			return null;
		}
		$document = \Bitrix\DocumentGenerator\Document::createByTemplate($template, $value);
		if(!$document->hasAccess())
		{
			$this->errorCollection[] = new Error('Access denied', static::ERROR_ACCESS_DENIED);
			return null;
		}
		$fields = $document->setValues($values)->getFields([], true, true);
		foreach($fields as &$field)
		{
			$field = $this->convertKeysToCamelCase($field);
		}
		return ['templateFields' => $fields];
	}

	/**
	 * @param array $select
	 * @param array|null $order
	 * @param array|null $filter
	 * @param PageNavigation|null $pageNavigation
	 * @param \CRestServer|null $restServer
	 * @return Page
	 */
	public function listAction(array $select = ['*'], array $order = null, array $filter = null, PageNavigation $pageNavigation = null, \CRestServer $restServer = null)
	{
		$isTitlePatternEnabled = Feature::isEnabled(DocumentTitlePattern::class);
		if (!$isTitlePatternEnabled)
		{
			$select = $this->removeTitlePatternFromSelect($select);
			$filter = $this->removeTitlePatternFromQueryParameters($filter);
			$order = $this->removeTitlePatternFromQueryParameters($order);
		}
		$isTimezoneEnabled = Timezone::isEnabled();
		if (!$isTimezoneEnabled)
		{
			$select = $this->removeTimezoneFromSelect($select);
			$filter = $this->removeTimezoneFromQueryParameters($filter);
			$order = $this->removeTimezoneFromQueryParameters($order);
		}

		$withProviders = $withUsers = false;
		if(($key = array_search('providers', $select)) !== false)
		{
			$withProviders = true;
			unset($select[$key]);
		}
		if(($key = array_search('users', $select)) !== false)
		{
			$withUsers = true;
			unset($select[$key]);
		}
		if(!is_array($filter))
		{
			$filter = [];
		}
		if(!isset($filter['isDeleted']) && !isset($filter['@isDeleted']) && !isset($filter['!isDeleted']))
		{
			$filter['isDeleted'] = 'N';
		}
		if($restServer)
		{
			$filter['moduleId'] = Driver::REST_MODULE_ID;
		}

		$this->prepareDateTimeFieldsForFilter($filter, ['createTime', 'updateTime']);
		$converter = new Converter(0);
		if(is_array($filter))
		{
			$filter = $converter->setFormat(Converter::TO_UPPER | Converter::KEYS | Converter::TO_SNAKE)->process($filter);
		}
		if(is_array($order))
		{
			$order = $converter->setFormat(Converter::TO_UPPER | Converter::KEYS | Converter::TO_SNAKE)->process($order);
		}
		if(is_array($select))
		{
			$select = $converter->setFormat(Converter::TO_UPPER | Converter::VALUES | Converter::TO_SNAKE)->process($select);
		}

		if(!in_array('ID', $select))
		{
			$select[] = 'ID';
		}

		$filter = array_merge($filter, Driver::getInstance()->getUserPermissions()->getFilterForTemplateList());

		$templates = TemplateTable::getList([
			'select' => $select,
			'filter' => $filter,
			'order' => $order ?? [],
			'offset' => $pageNavigation->getOffset(),
			'limit' => $pageNavigation->getLimit(),
		])->fetchAll();

		$buffer = [];
		foreach($templates as $template)
		{
			if (!$isTitlePatternEnabled)
			{
				unset($template['TITLE_PATTERN']);
			}
			if (!$isTimezoneEnabled)
			{
				unset($template['TIMEZONE']);
			}
			$template['download'] = $this->getTemplateDownloadLink($template['ID'], $template['UPDATE_TIME']);
			$buffer[$template['ID']] = $template;
		}
		$templates = $buffer;
		unset($buffer);

		if($withProviders)
		{
			$providers = TemplateProviderTable::getList(['filter' => ['TEMPLATE_ID' => array_keys($templates)]]);
			while($provider = $providers->fetch())
			{
				$templates[$provider['TEMPLATE_ID']]['PROVIDERS'][] = $provider['PROVIDER'];
			}
		}
		if($withUsers)
		{
			foreach($templates as &$template)
			{
				$template['USERS'] = [];
			}
			$users = TemplateUserTable::getList(['filter' => ['TEMPLATE_ID' => array_keys($templates)]]);
			while($user = $users->fetch())
			{
				$templates[$user['TEMPLATE_ID']]['USERS'][] = $user['ACCESS_CODE'];
			}
		}

		return new Page('templates', $this->convertKeysToCamelCase($templates), function() use ($filter)
		{
			return TemplateTable::getCount($filter);
		});
	}

	/**
	 * @param \Bitrix\DocumentGenerator\Template $template
	 * @param \CRestServer|null $restServer
	 * @return array
	 */
	public function getAction(\Bitrix\DocumentGenerator\Template $template, \CRestServer $restServer = null)
	{
		$result = [
			'template' => [
				'id' => $template->ID,
				'name' => $template->NAME,
				'region' => $template->REGION,
				'code' => $template->CODE,
				'download' => $template->getDownloadUrl(true),
				'active' => $template->ACTIVE,
				'moduleId' => $template->MODULE_ID,
				'numeratorId' => $template->NUMERATOR_ID,
				'withStamps' => $template->WITH_STAMPS,
				'providers' => $template->getDataProviders(),
				'users' => $template->getUsers(),
				'isDeleted' => $template->isDeleted() ? 'Y' : 'N',
				'sort' => $template->SORT,
				'createTime' => $template->CREATE_TIME,
				'updateTime' => $template->UPDATE_TIME,
			],
		];
		if (Feature::isEnabled(DocumentTitlePattern::class))
		{
			$result['template']['titlePattern'] = $template->TITLE_PATTERN;
		}
		if (Timezone::isEnabled())
		{
			$result['template']['timezone'] = $template->TIMEZONE;
		}

		return $result;
	}

	private function prepareTimezoneInput(array &$fields): void
	{
		$timezoneIsProvided = false;
		$timezone = null;
		foreach ($fields as $field => $value)
		{
			if (!is_string($field) || !$this->isTimezoneField($field))
			{
				continue;
			}

			if (!$timezoneIsProvided || $field === 'timezone')
			{
				$timezone = $value;
				$timezoneIsProvided = true;
			}

			unset($fields[$field]);
		}

		if (!$timezoneIsProvided || !Timezone::isEnabled())
		{
			return;
		}

		$fields['timezone'] = $timezone === '' ? null : $timezone;
	}

	private function validateTimezoneInput(array $fields): bool
	{
		if (!array_key_exists('timezone', $fields) || $fields['timezone'] === null)
		{
			return true;
		}

		if (is_string($fields['timezone']) && Timezone::isValid($fields['timezone']))
		{
			return true;
		}

		$this->addError(new Error(
			'Timezone must be a canonical IANA identifier.',
			'TIMEZONE_INVALID',
			['field' => 'timezone'],
		));

		return false;
	}

	private function prepareTitlePatternInput(array &$fields): void
	{
		$titlePatternIsProvided = false;
		$titlePattern = null;
		foreach ($fields as $field => $value)
		{
			if (!is_string($field) || !$this->isTitlePatternInputField($field))
			{
				continue;
			}

			if (!$titlePatternIsProvided || $field === 'titlePattern')
			{
				$titlePattern = $value;
				$titlePatternIsProvided = true;
			}

			unset($fields[$field]);
		}

		if (!$titlePatternIsProvided || !Feature::isEnabled(DocumentTitlePattern::class))
		{
			return;
		}

		$fields['titlePattern'] = is_string($titlePattern) && trim($titlePattern) === '' ? null : $titlePattern;
	}

	public function getTitlePatternDataAction(
		int $fileId,
		array $providers,
		string $moduleId,
		?\Bitrix\DocumentGenerator\Template $template = null,
		?string $titlePattern = null,
	): ?array
	{
		if (!Feature::isEnabled(DocumentTitlePattern::class))
		{
			$this->addTitlePatternOperationError(
				'TITLE_PATTERN_FEATURE_DISABLED',
				'DOCGEN_CONTROLLER_TEMPLATE_TITLE_PATTERN_FEATURE_DISABLED',
			);

			return null;
		}

		if (
			!$this->canModifyTemplates()
			|| ($template && !$this->canModifyTemplate((int)$template->ID))
		)
		{
			$this->addTitlePatternAccessError();

			return null;
		}

		if (!$this->canUseTitlePatternFile($fileId, $template))
		{
			return null;
		}

		$providerClassNames = $this->prepareTitlePatternProviders($providers, $moduleId);
		if ($providerClassNames === null)
		{
			return null;
		}

		$candidateTemplate = \Bitrix\DocumentGenerator\Template::loadFromArray([
			'FILE_ID' => $fileId,
			'BODY_TYPE' => Docx::class,
		]);
		$patternWasProvided = $this->isTitlePatternParameterProvided(func_num_args());
		if (!$patternWasProvided || $titlePattern === null || (is_string($titlePattern) && trim($titlePattern) === ''))
		{
			$pattern = new TitlePattern('');
			$validation = [
				'checked' => $patternWasProvided,
				'isValid' => $patternWasProvided ? true : null,
				'errors' => [],
			];
		}
		elseif (!is_string($titlePattern))
		{
			$this->addTitlePatternContextError();

			return null;
		}
		else
		{
			$pattern = new TitlePattern($titlePattern);
			$validationResult = $pattern->validate($candidateTemplate, $providerClassNames);
			$validation = [
				'checked' => true,
				'isValid' => $validationResult->isSuccess(),
				'errors' => $this->getTitlePatternValidationErrors($validationResult),
			];
		}

		$catalogResult = $pattern->getCatalog($candidateTemplate, $providerClassNames);

		return [
			'maxLength' => TitlePattern::MAX_LENGTH,
			'groups' => $catalogResult->getData()['groups'] ?? [],
			'validation' => $validation,
		];
	}

	protected function canModifyTemplates(): bool
	{
		return Driver::getInstance()->getUserPermissions()->canModifyTemplates();
	}

	protected function canModifyTemplate(int $templateId): bool
	{
		return Driver::getInstance()->getUserPermissions()->canModifyTemplate($templateId);
	}

	private function canUseTitlePatternFile(
		int $fileId,
		?\Bitrix\DocumentGenerator\Template $template,
	): bool
	{
		$file = $fileId > 0
			? FileTable::query()->setSelect(['ID'])->where('ID', $fileId)->setLimit(1)->fetch()
			: false
		;
		if (!$file)
		{
			$this->addTitlePatternContextError();

			return false;
		}

		$document = DocumentTable::query()
			->setSelect(['ID'])
			->setFilter([
				'LOGIC' => 'OR',
				'=FILE_ID' => $fileId,
				'=IMAGE_ID' => $fileId,
				'=PDF_ID' => $fileId,
			])
			->setLimit(1)
			->fetch()
		;
		if ($document)
		{
			$this->addTitlePatternAccessError();

			return false;
		}

		$templateQuery = TemplateTable::query()
			->setSelect(['ID'])
			->where('FILE_ID', $fileId)
		;
		if ($template)
		{
			$templateQuery->where('ID', '!=', (int)$template->ID);
		}

		if ($templateQuery->setLimit(1)->fetch())
		{
			$this->addTitlePatternAccessError();

			return false;
		}

		return true;
	}

	private function prepareTitlePatternProviders(array $providers, string $moduleId): ?array
	{
		if (
			$moduleId === ''
			|| !ModuleManager::isModuleInstalled($moduleId)
			|| !Loader::includeModule($moduleId)
			|| empty($providers)
		)
		{
			$this->addTitlePatternContextError();

			return null;
		}

		$providerClassNames = [];
		foreach ($providers as $provider)
		{
			if (!is_string($provider) || $provider === '')
			{
				$this->addTitlePatternContextError();

				return null;
			}

			$providerClassName = TemplateProviderTable::getClassNameFromFilterString($provider);
			if (!DataProviderManager::checkProviderName($providerClassName, $moduleId))
			{
				$this->addTitlePatternContextError();

				return null;
			}
			$providerClassNames[] = $providerClassName;
		}

		return array_values(array_unique($providerClassNames));
	}

	private function getTitlePatternValidationErrors(Result $result): array
	{
		if ($result->isSuccess())
		{
			return [];
		}

		$fieldName = $result->getError()?->getCustomData()['fieldName'] ?? null;

		return [[
			'code' => TitlePattern::ERROR_CODE,
			'message' => (string)Loc::getMessage('DOCGEN_CONTROLLER_TEMPLATE_TITLE_PATTERN_INVALID'),
			'placeholder' => $fieldName === null ? null : '{' . $fieldName . '}',
		]];
	}

	private function addTitlePatternAccessError(): void
	{
		$this->addTitlePatternOperationError(
			'TITLE_PATTERN_ACCESS_DENIED',
			'DOCGEN_CONTROLLER_TEMPLATE_TITLE_PATTERN_ACCESS_DENIED',
		);
	}

	private function addTitlePatternContextError(): void
	{
		$this->addTitlePatternOperationError(
			'TITLE_PATTERN_CONTEXT_INVALID',
			'DOCGEN_CONTROLLER_TEMPLATE_TITLE_PATTERN_CONTEXT_INVALID',
		);
	}

	private function addTitlePatternOperationError(string $code, string $messageCode): void
	{
		Loc::loadLanguageFile(__FILE__);
		$this->addError(new Error((string)Loc::getMessage($messageCode), $code));
	}

	private function isTitlePatternParameterProvided(int $argumentCount): bool
	{
		$sourceParametersList = $this->getSourceParametersList();
		if ($sourceParametersList === null)
		{
			return $argumentCount >= 5;
		}

		foreach ($sourceParametersList as $source)
		{
			if (is_array($source) && array_key_exists('titlePattern', $source))
			{
				return true;
			}

			if ($source instanceof \ArrayAccess && $source->offsetExists('titlePattern'))
			{
				return true;
			}
		}

		return false;
	}

	private function validateTitlePattern(
		array $fields,
		?\Bitrix\DocumentGenerator\Template $storedTemplate = null,
	): bool
	{
		if (!array_key_exists('titlePattern', $fields) || $fields['titlePattern'] === null)
		{
			return true;
		}

		if (!is_string($fields['titlePattern']))
		{
			$this->addTitlePatternError('Title pattern must be a string');

			return false;
		}

		$fileId = (int)($fields['fileId'] ?? $storedTemplate?->FILE_ID ?? 0);
		$candidateTemplate = \Bitrix\DocumentGenerator\Template::loadFromArray([
			'FILE_ID' => $fileId,
			'BODY_TYPE' => $fields['bodyType'] ?? $storedTemplate?->BODY_TYPE ?? Docx::class,
		]);
		$providers = $fields['providers'] ?? [];
		if (!is_array($providers))
		{
			$providers = [];
		}
		if (empty($providers) && $storedTemplate)
		{
			$providers = $storedTemplate->getDataProviders(true);
		}
		$providerClassNames = array_map(
			[TemplateProviderTable::class, 'getClassNameFromFilterString'],
			$providers,
		);
		$moduleId = $fields['moduleId'] ?? $storedTemplate?->MODULE_ID ?? Driver::REST_MODULE_ID;
		$providerClassNames = array_values(array_filter(
			$providerClassNames,
			fn(string $provider): bool => DataProviderManager::checkProviderName($provider, $moduleId),
		));
		$result = (new TitlePattern($fields['titlePattern']))->validate($candidateTemplate, $providerClassNames);
		if ($result->isSuccess())
		{
			return true;
		}

		$this->logTitlePatternValidationFailure($result, $storedTemplate?->ID);
		Loc::loadLanguageFile(__FILE__);
		$this->addTitlePatternError(
			(string)Loc::getMessage('DOCGEN_CONTROLLER_TEMPLATE_TITLE_PATTERN_INVALID'),
		);

		return false;
	}

	private function logTitlePatternValidationFailure(Result $result, ?int $templateId): void
	{
		(new LoggerFactory())
			->createById(self::TITLE_PATTERN_LOGGER_ID, isCheckEnabledFromRegistry: false)
			->warning('{method}: rejected title pattern of template {templateId}: {errors}', [
				'method' => __METHOD__,
				'templateId' => $templateId ?? 0,
				'errors' => implode('; ', $result->getErrorMessages()),
			])
		;
	}

	private function isTitlePatternInputField(string $field): bool
	{
		return mb_strtolower(str_replace('_', '', $field)) === 'titlepattern';
	}

	private function addTitlePatternError(string $message): void
	{
		$this->addError(new Error(
			$message,
			TitlePattern::ERROR_CODE,
			['field' => 'titlePattern'],
		));
	}

	private function removeTitlePatternFromSelect(array $select): array
	{
		return array_values(array_filter(
			$select,
			fn(mixed $field): bool => !is_string($field) || !$this->isTitlePatternField($field),
		));
	}

	private function removeTitlePatternFromQueryParameters(?array $parameters): array
	{
		$parameters ??= [];
		foreach ($parameters as $field => $value)
		{
			if (is_string($field) && $this->isTitlePatternField($field))
			{
				unset($parameters[$field]);

				continue;
			}

			if (is_array($value))
			{
				$parameters[$field] = $this->removeTitlePatternFromQueryParameters($value);
			}
		}

		return $parameters;
	}

	private function isTitlePatternField(string $field): bool
	{
		$field = ltrim($field, '!<>=%@?*');
		$field = str_replace('_', '', $field);

		return mb_strtolower($field) === 'titlepattern';
	}

	private function removeTimezoneFromSelect(array $select): array
	{
		return array_values(array_filter(
			$select,
			fn(mixed $field): bool => !is_string($field) || !$this->isTimezoneField($field),
		));
	}

	private function removeTimezoneFromQueryParameters(?array $parameters): array
	{
		$parameters ??= [];
		foreach ($parameters as $field => $value)
		{
			if (is_string($field) && $this->isTimezoneField($field))
			{
				unset($parameters[$field]);

				continue;
			}

			if (is_array($value))
			{
				$parameters[$field] = $this->removeTimezoneFromQueryParameters($value);
			}
		}

		return $parameters;
	}

	private function isTimezoneField(string $field): bool
	{
		$field = ltrim($field, '!<>=%@?*');
		$field = str_replace('_', '', $field);

		return mb_strtolower($field) === 'timezone';
	}

	/**
	 * @param $templateId
	 * @param null $updateTime
	 * @return ContentUri
	 */
	protected function getTemplateDownloadLink($templateId, $updateTime = null)
	{
		if(!$updateTime)
		{
			$updateTime = time();
		}
		return new ContentUri(UrlManager::getInstance()->create('documentgenerator.api.template.download', ['id' => $templateId, 'ts' => $updateTime])->getUri());
	}
}
