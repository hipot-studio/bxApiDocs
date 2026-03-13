<?php

namespace Bitrix\Call\Controller\Desktop;

use Bitrix\Main\Engine\Controller;
use Bitrix\UI\InfoHelper;

/**
 * @internal
 */
class Background extends Controller
{
	/**
	 * @restMethod call.Desktop.Background.get
	 */
	public function getAction(): array
	{
		$diskFolder = \Bitrix\Call\Desktop\Background::getUploadFolder();
		$diskFolderId = $diskFolder? (int)$diskFolder->getId(): 0;
		$infoHelperParams = \Bitrix\Main\Loader::includeModule('ui')? InfoHelper::getInitParams(): [];

		return [
			'backgrounds' => [
				'default' => \Bitrix\Call\Desktop\Background::get(),
				'custom' => \Bitrix\Call\Desktop\Background::getCustom(),
			],
			'upload' => [
				'folderId' => $diskFolderId,
			],
			'limits' => \Bitrix\Call\Desktop\Background::getLimitForJs(),
			'infoHelperParams' => $infoHelperParams,
		];
	}

	/**
	 * @restMethod call.Desktop.Background.commit
	 */
	public function commitAction(int $fileId)
	{
		$result = \CIMDisk::CommitBackgroundFile(
			$this->getCurrentUser()->getId(),
			$fileId
		);

		if (!$result)
		{
			$this->addError(new \Bitrix\Main\Error(
				"Specified fileId is not located in background folder.",
				"FILE_ID_ERROR"
			));

			return false;
		}

		return [
			'result' => true
		];
	}

	/**
	 * @restMethod call.Desktop.Background.delete
	 */
	public function deleteAction(int $fileId)
	{
		$result = \CIMDisk::DeleteBackgroundFile(
			$this->getCurrentUser()->getId(),
			$fileId
		);

		if (!$result)
		{
			$this->addError(new \Bitrix\Main\Error(
				"Specified fileId is not located in background folder.",
				"FILE_ID_ERROR"
			));

			return false;
		}

		return [
			'result' => true
		];
	}
}