<?php

use Bitrix\Disk\Driver;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPDiskDetailActivity extends CBPActivity
{
	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			'SourceId' => '',

			//return properties
			'ObjectId' => '',
			'Type' => '',
			'Name' => '',
			'SizeBytes' => 0,
			'SizeFormatted' => '',
			'DetailUrl' => '',
			'DownloadUrl' => '',
		];

		//return properties mapping
		$this->setPropertiesTypes([
			'ObjectId' => [
				'Type' => 'int',
				'Multiple' => true,
			],
			'Type' => [
				'Type' => 'string',
				'Multiple' => true,
			],
			'Name' => [
				'Type' => 'string',
				'Multiple' => true,
			],
			'SizeBytes' => [
				'Type' => 'int',
				'Multiple' => true,
			],
			'SizeFormatted' => [
				'Type' => 'string',
				'Multiple' => true,
			],
			'DetailUrl' => [
				'Type' => 'string',
				'Multiple' => true,
			],
			'DownloadUrl' => [
				'Type' => 'string',
				'Multiple' => true,
			],
		]);
	}

	protected function reInitialize()
	{
		parent::reInitialize();

		$this->ObjectId = '';
		$this->Type = '';
		$this->Name = '';
		$this->SizeBytes = 0;
		$this->SizeFormatted = '';
		$this->DetailUrl = '';
		$this->DownloadUrl = '';
	}

	public function execute()
	{
		if (!Loader::includeModule('disk'))
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$ids = $types = $names = $sizes = $formattedSizes = $detailUrls = $downloadUrls = [];
		$urlManager = Driver::getInstance()->getUrlManager();
		$sourceIds = (array)$this->SourceId;

		foreach ($sourceIds as $sourceId)
		{
			$sourceObject = \Bitrix\Disk\BaseObject::loadById($sourceId);

			if (!$sourceObject)
			{
				$this->writeToTrackingService(Loc::getMessage('BPDD_SOURCE_ID_ERROR'));

				continue;
			}

			$isFile = ($sourceObject instanceof \Bitrix\Disk\File);
			$size = $sourceObject->getSize();

			$ids[] = $sourceId;
			$types[] = $isFile ? 'FILE' : 'FOLDER';
			$names[] = $sourceObject->getName();
			$sizes[] = $size;
			$formattedSizes[] = \CFile::FormatSize($size);
			$downloadUrls[] = $isFile ? $urlManager->getUrlForDownloadFile($sourceObject, true) : '';
			$detailUrls[] = $urlManager->encodeUrn(
				$urlManager->getHostUrl()
				.($isFile ? $urlManager->getPathFileDetail($sourceObject) : $urlManager->getPathFolderList($sourceObject))
			);

		}

		$this->ObjectId = $ids;
		$this->Type = $types;
		$this->Name = $names;
		$this->SizeBytes = $sizes;
		$this->SizeFormatted = $formattedSizes;
		$this->DetailUrl = $detailUrls;
		$this->DownloadUrl = $downloadUrls;

		return CBPActivityExecutionStatus::Closed;
	}

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null)
	{
		$arErrors = [];
		if ($user && !$user->isAdmin())
		{
			$arErrors[] = [
				'code' => 'AccessDenied',
				'parameter' => 'Admin',
				'message' => Loc::getMessage('BPDD_ACCESS_DENIED'),
			];
		}

		if (empty($arTestProperties['SourceId']))
		{
			$arErrors[] = [
				'code' => 'NotExist',
				'parameter' => 'SourceId',
				'message' => Loc::getMessage('BPDD_EMPTY_SOURCE_ID'),
			];
		}

		return array_merge($arErrors, parent::validateProperties($arTestProperties, $user));
	}

	public static function getPropertiesDialog(
		$documentType,
		$activityName,
		$arWorkflowTemplate,
		$arWorkflowParameters,
		$arWorkflowVariables,
		$currentValues = null,
		$formName = ''
	)
	{
		if (!Loader::includeModule('disk'))
		{
			return '';
		}

		$runtime = CBPRuntime::getRuntime();

		$arMap = [
			'SourceId' => 'source_id',
		];

		if (!is_array($currentValues))
		{
			$arCurrentActivity = &CBPWorkflowTemplateLoader::findActivityByName($arWorkflowTemplate, $activityName);
			foreach ($arMap as $k => $v)
			{
				$currentValues[$arMap[$k]] = $arCurrentActivity['Properties'][$k] ?? '';
			}
		}

		if (
			empty($currentValues['source_id'])
			&& isset($currentValues['source_id_x'])
			&& CBPDocument::isExpression($currentValues['source_id_x'])
		)
		{
			$currentValues['source_id'] = $currentValues['source_id_x'];
		}

		return $runtime->executeResourceFile(
			__FILE__,
			'properties_dialog.php',
			[
				'arCurrentValues' => $currentValues,
				'formName' => $formName,
			]
		);
	}

	public static function GetPropertiesDialogValues(
		$documentType,
		$activityName,
		&$arWorkflowTemplate,
		&$arWorkflowParameters,
		&$arWorkflowVariables,
		$currentValues,
		&$arErrors
	)
	{
		$arErrors = [];

		$properties = ['SourceId' => $currentValues['source_id']];

		if (
			empty($properties['SourceId'])
			&& isset($currentValues['source_id_x'])
			&& CBPDocument::isExpression($currentValues['source_id_x'])
		)
		{
			$properties['SourceId'] = $currentValues['source_id_x'];
		}

		$arErrors = self::validateProperties(
			$properties,
			new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser)
		);

		if (count($arErrors) > 0)
		{
			return false;
		}

		$arCurrentActivity = &CBPWorkflowTemplateLoader::findActivityByName($arWorkflowTemplate, $activityName);
		$arCurrentActivity['Properties'] = $properties;

		return true;
	}
}
