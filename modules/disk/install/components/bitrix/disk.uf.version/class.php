<?php
use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\DownloadAccess;
use Bitrix\Disk\Internals\BaseComponent;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Ui\FileAttributes;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

class CDiskUfVersionComponent extends BaseComponent
{
	protected function prepareParams()
	{
		if(!empty($this->arParams['DISABLE_LOCAL_EDIT']))
		{
			$this->arParams['DISABLE_LOCAL_EDIT'] = true;
		}
		else
		{
			$this->arParams['DISABLE_LOCAL_EDIT'] = null;
		}

		return $this;
	}

	protected function processActionDefault()
	{
		$this->arResult = array(
			'ONLY_HEAD_VERSION' => !Configuration::isEnabledKeepVersion(),
			'VERSIONS' => $this->loadData(),
			'UID' => randString(5),
		);

		$this->includeComponentTemplate();
	}

	private function loadData()
	{
		if(empty($this->arParams['PARAMS']['arUserField']))
		{
			return array();
		}
		if (!$this->getUser())
		{
			return [];
		}

		$userId = $this->getUser()->getId();
		$values = $this->arParams['PARAMS']['arUserField']['VALUE'];
		if(!is_array($this->arParams['PARAMS']['arUserField']['VALUE']))
		{
			$values = array($values);
		}
		$urlManager = \Bitrix\Disk\Driver::getInstance()->getUrlManager();
		$isEnabledObjectLock = Configuration::isEnabledObjectLock();

		$attachedModels = $this->loadAttachedObjects($values);
		$this->preloadDownloadAccessForAttachedObjects($attachedModels);

		$versions = array();
		foreach($values as $value)
		{
			$attachedObjectId = (int)$value;
			if($attachedObjectId <= 0)
			{
				continue;
			}
			/** @var \Bitrix\Disk\AttachedObject $attachedModel */
			$attachedModel = $attachedModels[$attachedObjectId] ?? null;
			if(!$attachedModel)
			{
				continue;
			}
			$version = $attachedModel->getVersion();
			$file = $version?->getObject();
			if (!$version || !$file)
			{
				continue;
			}
			$extension = $version->getExtension();
			$canDownload = $attachedModel->canDownload($this->getUser()->getId());

			$downloadUrl = $canDownload
				? $urlManager->getUrlUfController('download', ['attachedId' => $attachedModel->getId()])
				: null
			;

			$versionData = array(
				'ID' => $attachedModel->getId(),
				'NAME' => $version->getName(),
				'CONVERT_EXTENSION' => DocumentHandler::isNeedConvertExtension($extension),
				'EDITABLE' => DocumentHandler::isEditable($extension),
				'CAN_UPDATE' => $attachedModel->canUpdate($userId),
				'FROM_EXTERNAL_SYSTEM' => $file->getContentProvider() && $file->getCreatedBy() == $userId,
				'EXTENSION' => $extension,
				'SIZE' => \CFile::formatSize($version->getSize()),
				'HISTORY_URL' => $urlManager->getUrlUfController('history', array('attachedId' => $attachedModel->getId())),
				'DOWNLOAD_URL' => $downloadUrl,
				'CAN_DOWNLOAD' => $canDownload,
				'COPY_TO_ME_URL' => $urlManager->getUrlUfController('copyTome', array('attachedId' => $attachedModel->getId())),
				'VIEW_URL' => $urlManager->getUrlToShowAttachedFileByService($attachedModel->getId(), 'gvdrive'),
				'EDIT_URL' => $urlManager->getUrlToStartEditUfFileByService($attachedModel->getId(), 'gdrive'),
				'GLOBAL_CONTENT_VERSION' => $version->getGlobalContentVersion(),
				'CREATED_BY' => null,
				'IS_LOCKED' => null,
				'IS_LOCKED_BY_SELF' => null,
			);

			if($isEnabledObjectLock && $file->getLock())
			{
				$objectLock = $file->getLock();
				$versionData['CREATED_BY'] = $objectLock->getCreatedBy();
				$versionData['IS_LOCKED'] = true;
				$versionData['IS_LOCKED_BY_SELF'] = $this->getUser()->getId() == $objectLock->getCreatedBy();
			}

			$sourceUri = new \Bitrix\Main\Web\Uri(
				$downloadUrl ?? $urlManager->getUrlUfController('show', array('attachedId' => $attachedModel->getId()))
			);
			$attr = FileAttributes::buildByFileId($attachedModel->getFileId(), $sourceUri, $file, $version)
				->setObjectId($attachedModel->getObjectId())
				->setAttachedObjectId($attachedModel->getId())
				->setVersionId($attachedModel->getVersionId())
				->setTitle($version->getName())
				->setDownloadDisabled(!$canDownload)
				->addAction([
					'type' => 'copyToMe',
					'text' => Loc::getMessage('DISK_UF_VERSION_ACTION_SAVE_TO_OWN_FILES'),
					'action' => 'BX.Disk.Viewer.Actions.runActionCopyToMe',
					'params' => [
						'attachedObjectId' => $attachedModel->getId(),
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

			$versionData['ATTRIBUTES_FOR_VIEWER'] = $attr;


			$versions[] = $versionData;
		}
		unset($value);

		return $versions;
	}

	/**
	 * @return array<int, AttachedObject>
	 */
	protected function loadAttachedObjects(array $values): array
	{
		$attachedObjects = [];

		foreach ($values as $value)
		{
			$attachedObjectId = (int)$value;

			if ($attachedObjectId <= 0 || array_key_exists($attachedObjectId, $attachedObjects))
			{
				continue;
			}

			$attachedObject = $this->loadAttachedObject($attachedObjectId);

			if ($attachedObject instanceof AttachedObject)
			{
				$attachedObjects[$attachedObjectId] = $attachedObject;
			}
		}

		return $attachedObjects;
	}

	protected function loadAttachedObject(int $attachedObjectId): ?AttachedObject
	{
		return AttachedObject::loadById($attachedObjectId, ['OBJECT', 'VERSION.OBJECT']);
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

}
