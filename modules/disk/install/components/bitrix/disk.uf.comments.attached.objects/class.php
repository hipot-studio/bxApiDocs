<?php
use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\GoogleHandler;
use Bitrix\Disk\Document\LocalDocumentController;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\DownloadAccess;
use Bitrix\Disk\Internals\BaseComponent;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Ui;
use Bitrix\Disk\Ui\FileAttributes;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Localization\Loc;

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

if(!\Bitrix\Main\Loader::includeModule('disk'))
{
	return;
}

class CDiskUfCommentsAttachesComponent extends BaseComponent
{
	protected function prepareParams()
	{
		if(isset($this->arParams['ENABLE_AUTO_BINDING_VIEWER']))
		{
			$this->arParams['ENABLE_AUTO_BINDING_VIEWER'] = (bool)$this->arParams['ENABLE_AUTO_BINDING_VIEWER'];
		}
		else
		{
			$this->arParams['ENABLE_AUTO_BINDING_VIEWER'] = true;
		}

		if(!empty($this->arParams['DISABLE_LOCAL_EDIT']))
		{
			$this->arParams['DISABLE_LOCAL_EDIT'] = true;
		}
		else
		{
			$this->arParams['DISABLE_LOCAL_EDIT'] = null;
		}

		return parent::prepareParams();
	}

	protected function processBeforeAction($actionName)
	{
		if(!$this->checkRequiredInputParams($this->arParams, array('MAIN_ENTITY', 'COMMENTS_MODE', 'COMMENTS_DATA',)))
		{
			return false;
		}

		return parent::processBeforeAction($actionName);
	}

	protected function processActionDefault()
	{
		$attachedObjects = $this->getAttachedObjects(
			$this->arParams['COMMENTS_MODE'],
			$this->arParams['COMMENTS_DATA']
		);

		$this->arResult = array(
			'FILES' => $this->prepareObjectToResult($attachedObjects),
			'UID' => $this->getComponentId(),
		);

		$this->arResult['CLOUD_DOCUMENT'] = array();

		$documentHandlerName = null;
		$documentHandlerCode = null;
		$isLocal = null;

		if(Configuration::canCreateFileByCloud())
		{
			$documentServiceCode = \Bitrix\Disk\UserConfiguration::getDocumentServiceCode();
			if(!$documentServiceCode)
			{
				$documentServiceCode = LocalDocumentController::getCode();
			}
			if($this->arParams['DISABLE_LOCAL_EDIT'] && LocalDocumentController::isLocalService($documentServiceCode))
			{
				$documentServiceCode = GoogleHandler::getCode();
			}
			if(LocalDocumentController::isLocalService($documentServiceCode))
			{
				$documentHandlerName = LocalDocumentController::getName();
				$documentHandlerCode = LocalDocumentController::getCode();
				$isLocal = true;
			}
			else
			{
				$defaultDocumentHandler = \Bitrix\Disk\Driver::getInstance()
					->getDocumentHandlersManager()
					->getDefaultServiceForCurrentUser()
				;
				if($defaultDocumentHandler)
				{
					$documentHandlerName = $defaultDocumentHandler::getName();
					$documentHandlerCode = $defaultDocumentHandler::getCode();
					$isLocal = false;
				}
			}
		}
		if($documentHandlerCode)
		{
			$this->arResult['CLOUD_DOCUMENT'] = array(
				'DEFAULT_SERVICE' => $documentHandlerCode,
				'DEFAULT_SERVICE_LABEL' => $documentHandlerName,
				'IS_LOCAL' => $isLocal,
			);
			$this->arResult['DEFAULT_DOCUMENT_SERVICE_EDIT_NAME'] = $documentHandlerName;
			$this->arResult['DEFAULT_DOCUMENT_SERVICE_EDIT_CODE'] = $documentHandlerCode;
		}

		if(is_array($this->arParams['PARAMS']))
		{
			$this->arParams = array_merge($this->arParams, $this->arParams['PARAMS']);
		}

		$this->arResult['ENABLED_MOD_ZIP'] = \Bitrix\Disk\ZipNginx\Configuration::isEnabled();
		if($this->arResult['ENABLED_MOD_ZIP'])
		{
			$this->arResult['ATTACHED_IDS'] = array();
			$this->arResult['COMMON_SIZE'] = 0;
			$this->arResult['CAN_DOWNLOAD_ARCHIVE'] = true;

			foreach($this->arResult['FILES'] as $fileData)
			{
				if (!$fileData['CAN_DOWNLOAD'])
				{
					$this->arResult['CAN_DOWNLOAD_ARCHIVE'] = false;
				}

				$this->arResult['ATTACHED_IDS'][] = $fileData['ID'];
				$this->arResult['COMMON_SIZE'] += $fileData['SIZE_BYTES'];
			}

			if ($this->arResult['CAN_DOWNLOAD_ARCHIVE'])
			{
				$this->arResult['DOWNLOAD_ARCHIVE_URL'] = \Bitrix\Disk\Driver::getInstance()->getUrlManager()->getUrlUfController('downloadArchive', array(
					'attachedIds' => $this->arResult['ATTACHED_IDS'],
					'signature' => \Bitrix\Disk\Security\ParameterSigner::getArchiveSignature($this->arResult['ATTACHED_IDS']),
				));
			}
		}

		$this->includeComponentTemplate('show');
	}

	/**
	 * @param AttachedObject[] $attachedObjects
	 * @return array
	 */
	private function prepareObjectToResult(array $attachedObjects)
	{
		$files = array();
		$userId = $this->getUser()->getId();
		$urlManager = \Bitrix\Disk\Driver::getInstance()->getUrlManager();

		$this->preloadDownloadAccessForAttachedObjects($attachedObjects);

		foreach($attachedObjects as $attachedObject)
		{
			$file = $attachedObject->getFile();
			if(!$file)
			{
				continue;
			}

			$id = $attachedObject->getId();
			$name = $file->getName();
			$extension = $file->getExtension();
			$canDownload = $attachedObject->canDownload($userId);

			$downloadUrl = $canDownload
				? $urlManager->getUrlUfController('download', ['attachedId' => $id])
				: null
			;

			$data = array(
				'ID' => $id,
				'NAME' => $name,
				'CONVERT_EXTENSION' => DocumentHandler::isNeedConvertExtension($extension),
				'EDITABLE' => DocumentHandler::isEditable($extension),
				'CAN_UPDATE' => $attachedObject->canUpdate($userId),

				'FROM_EXTERNAL_SYSTEM' => $file->getContentProvider() && $file->getCreatedBy() == $userId,

				'EXTENSION' => $extension,
				'SIZE' => \CFile::formatSize($file->getSize()),
				'SIZE_BYTES' => $file->getSize(),
				'XML_ID' => $file->getXmlId(),
				'FILE_ID' => $file->getId(),

				'VIEW_URL' => $urlManager->getUrlToShowAttachedFileByService($id, 'gvdrive'),
				'EDIT_URL' => $urlManager->getUrlToStartEditUfFileByService($id, 'gdrive'),
				'DOWNLOAD_URL' => $downloadUrl,
				'CAN_DOWNLOAD' => $canDownload,
				'COPY_TO_ME_URL' => $urlManager->getUrlUfController('copyToMe', array('attachedId' => $id)),

				'DELETE_URL' => ''
			);
			if(\Bitrix\Disk\TypeFile::isImage($file))
			{
				$data['PREVIEW_URL'] = $urlManager->getUrlUfController('show', array('attachedId' => $id));
				$data['IMAGE'] = $file->getFile();
			}

			$data['CURRENT_USER_IS_OWNER'] = $attachedObject->getCreatedBy() == $this->getUser()->getId();
			$data['ALLOW_AUTO_COMMENT'] = $attachedObject->getAllowAutoComment();
			$sourceUrl = $downloadUrl ?? $urlManager->getUrlUfController('show', ['attachedId' => $id]);
			$sourceUri = new \Bitrix\Main\Web\Uri($sourceUrl);

			$attr = FileAttributes::buildByFileId($file->getFileId(), $sourceUri, $file)
				->setObjectId($attachedObject->getObjectId())
				->setAttachedObjectId($attachedObject->getId())
				->setTitle($file->getName())
				->setGroupBy($this->componentId)
				->setDownloadDisabled(!$canDownload)
				->addAction([
					'type' => 'copyToMe',
					'text' => Loc::getMessage('DISK_UF_ATTACHED_ACTION_SAVE_TO_OWN_FILES'),
					'action' => 'BX.Disk.Viewer.Actions.runActionCopyToMe',
					'params' => [
						'attachedObjectId' => $id,
					],
					'extension' => 'disk.viewer.actions',
					'buttonIconClass' => 'ui-btn-icon-cloud',
				])
			;

			if ($canDownload)
			{
				$attr->addAction([
					'type' => 'download',
				]);
			}

			if ($data['CAN_UPDATE'] && $data['EDITABLE'])
			{
				$documentName = \CUtil::JSEscape($file->getName());
				$items = [];
				foreach ($this->getDocumentHandlersForEditingFile() as $handlerData)
				{
					$items[] = [
						'text' => $handlerData['name'],
						'onclick' => "BX.Disk.Viewer.Actions.runActionEdit({name: '{$documentName}', attachedObjectId: {$id}, serviceCode: '{$handlerData['code']}'})",
					];
				}
				$attr->addAction([
					'type' => 'edit',
					'action' => 'BX.Disk.Viewer.Actions.runActionDefaultEdit',
					'params' => [
						'attachedObjectId' => $id,
						'name' => $documentName,
					],
					'items' => $items,
				]);
			}

			$data['ATTRIBUTES_FOR_VIEWER'] = $attr;


			$files[] = $data;
		}
		unset($attachedObject);

		\Bitrix\Main\Type\Collection::sortByColumn($files, 'ID');

		return $files;
	}

	protected function preloadDownloadAccessForAttachedObjects(array $attachedObjects): void
	{
		$files = [];

		foreach ($attachedObjects as $attachedObject)
		{
			if (!$attachedObject instanceof AttachedObject)
			{
				continue;
			}

			$file = $attachedObject->getFile();
			if ($file instanceof File)
			{
				$files[] = $file;
			}
		}

		if (empty($files))
		{
			return;
		}

		$this->getDownloadAccess()->preload($files);
	}

	protected function getDownloadAccess(): DownloadAccess
	{
		return ServiceLocator::getInstance()->get(DownloadAccess::class);
	}

	/**
	 * @return \Bitrix\Disk\Document\DocumentHandler[]
	 */
	private function listCloudHandlersForCreatingFile()
	{
		if (!\Bitrix\Disk\Configuration::canCreateFileByCloud())
		{
			return array();
		}

		return array_values(Driver::getInstance()->getDocumentHandlersManager()->getHandlersForCreatingFile());
	}

	private function getDocumentHandlersForEditingFile()
	{
		$handlers = [];
		foreach ($this->listCloudHandlersForCreatingFile() as $handler)
		{
			$handlers[] = [
				'code' => $handler::getCode(),
				'name' => $handler::getName(),
			];
		}

		return array_merge($handlers, [[
			'code' => LocalDocumentController::getCode(),
			'name' => LocalDocumentController::getName(),
		]]);
	}

	private function getAttachedObjects($mode, array $commentsData)
	{
		if($mode === 'forum')
		{
			return $this->getAttachedObjectByForum($commentsData);
		}

		return array();
	}

	/**
	 * @param array $commentsData
	 * @return static[]
	 */
	private function getAttachedObjectByForum(array $commentsData)
	{
		$userFieldManager = \Bitrix\Disk\Driver::getInstance()->getUserFieldManager();
		list($connectorClass, $moduleId) = $userFieldManager->getConnectorDataByEntityType('forum_message');

		if(!\Bitrix\Main\Loader::includeModule($moduleId))
		{
			return array();
		}

		return AttachedObject::getModelList(array(
			'filter' => array(
				'=ENTITY_TYPE' => $connectorClass,
				'=MODULE_ID' => $moduleId,
				'=VERSION_ID' => null,
			),
			'with' => array('OBJECT'),
			'runtime' => array(
				new ReferenceField(
					'M',
					'Bitrix\Forum\MessageTable',
					array(
						'=this.ENTITY_ID' => 'ref.ID',
						'=ref.TOPIC_ID' => new SqlExpression('?i', $commentsData['TOPIC_ID']),
						'=ref.FORUM_ID' => new SqlExpression('?i', $commentsData['FORUM_ID']),
					),
					array(
						'join_type' => 'INNER'
					)
				)
			),
		));
	}
}
