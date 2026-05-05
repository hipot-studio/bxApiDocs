<?php
use Bitrix\Disk\Internals\DiskComponent;
use Bitrix\Main\ArgumentException;

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

class CDiskFileUploadComponent extends DiskComponent
{
	protected $editMode = false;

	protected function prepareParams()
	{
		//fix for compatible
		if(
			empty($this->arParams['STORAGE']) &&
			empty($this->arParams['STORAGE_MODULE_ID']) &&
			!empty($this->arParams['FOLDER'])
		)
		{
			if(!($this->arParams['FOLDER'] instanceof \Bitrix\Disk\Folder))
			{
				throw new ArgumentException('FOLDER must be instance of \Bitrix\Disk\Folder');
			}
			$this->arParams['STORAGE'] = $this->arParams['FOLDER']->getStorage();
		}

		return parent::prepareParams();
	}

	protected function processActionDefault()
	{
		if($this->storage->isEnabledBizProc() && \Bitrix\Disk\Integration\BizProcManager::isAvailable())
		{
			$storageId = $this->storage->getId();

			$documentData = [
				'DISK' => \Bitrix\Disk\BizProcDocument::generateDocumentComplexType($storageId),
				'WEBDAV' => \Bitrix\Disk\BizProcDocumentCompatible::generateDocumentComplexType($storageId),
			];

			if (!empty($this->arParams['FILE_ID']))
			{
				$autoExecute = CBPDocumentEventType::Edit;
			}
			else
			{
				$autoExecute = CBPDocumentEventType::Create;
			}

			$this->arParams['BIZPROC_PARAMETERS'] = false;
			$this->arParams['BIZPROC_PARAMETERS_REQUIRED'] = [];

			$workflowTemplateId = '';
			$hasWebDavTemplatesWithParams = false;
			$hasDiskTemplatesWithParams = false;

			foreach($documentData as $nameModule => $data)
			{
				$workflowTemplateObject = CBPWorkflowTemplateLoader::getList(
					[],
					['DOCUMENT_TYPE' => $data, 'AUTO_EXECUTE' => $autoExecute, 'ACTIVE' => 'Y'],
					false,
					false,
					['ID', 'PARAMETERS']
				);
				while ($workflowTemplate = $workflowTemplateObject->getNext())
				{
					if (!empty($workflowTemplate['PARAMETERS']))
					{
						foreach ($workflowTemplate['PARAMETERS'] as $parametersId => $parameters)
						{
							if ($parameters['Required'])
							{
								$this->arParams['BIZPROC_PARAMETERS_REQUIRED'][] =
									'bizproc'
									. $workflowTemplate['ID']
									. '_'
									. $parametersId
								;
							}
						}

						$this->arParams['BIZPROC_PARAMETERS'] = true;

						if ($nameModule === 'DISK')
						{
							$hasDiskTemplatesWithParams = true;
						}

						if ($nameModule === 'WEBDAV')
						{
							$hasWebDavTemplatesWithParams = true;
						}
					}

					$workflowTemplateId = $workflowTemplate['ID'];
				}
			}

			$this->arParams['STATUS_START_BIZPROC'] = !empty($workflowTemplateId);

			if (
				defined('CBPRuntime::ACTIVITY_API_VERSION')
				&& \CBPRuntime::ACTIVITY_API_VERSION >= 2
				&& !$hasWebDavTemplatesWithParams
				&& $hasDiskTemplatesWithParams
			)
			{
				$this->arResult['SIGNED_DOCUMENT_TYPE'] = \CBPDocument::signDocumentType(
					\Bitrix\Disk\BizProcDocument::generateDocumentComplexType($storageId)
				);
				$this->arResult['SIGNED_DOCUMENT_ID'] =
					!empty($this->arParams['FILE_ID'])
						? \CBPDocument::signDocumentType(
							\Bitrix\Disk\BizProcDocument::getDocumentComplexId($this->arParams['FILE_ID'])
					)
						: ''
				;
			}
		}

		$this->arParams['STORAGE_ID'] = $this->storage->getId();

		$this->includeComponentTemplate();
	}
}
