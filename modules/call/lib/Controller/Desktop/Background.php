<?php

namespace Bitrix\Call\Controller\Desktop;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\UI\InfoHelper;

/**
 * @internal
 */
class Background extends Controller
{
	public function configureActions(): array
	{
		return [
			// A guest of a public conference has no portal account, and the web background is offered
			// to them like to anybody else. With the inherited authentication filter the gallery never
			// answers them and blur stays the only choice. Opening it is safe because an anonymous
			// caller is answered with the shipped catalogue alone.
			'getWeb' => [
				'-prefilters' => [
					ActionFilter\Authentication::class,
				],
			],
		];
	}

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
	 * @restMethod call.Desktop.Background.getWeb
	 */
	public function getWebAction(): array
	{
		return [
			'backgrounds' => \Bitrix\Call\Desktop\Background::getWebFiles($this->hasPortalAccount()),
		];
	}

	/**
	 * The intranet themes belong to the portal and are only listed for somebody who belongs to it.
	 * Being authorized is not the same thing: a guest of a public conference is registered as a
	 * user of their own, with an id of their own, and passes any plain check for one.
	 */
	private function hasPortalAccount(): bool
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		if ($userId <= 0 || !\Bitrix\Main\Loader::includeModule('intranet'))
		{
			// without the module there are no portal themes to list either
			return false;
		}

		return \Bitrix\Intranet\Util::isIntranetUser($userId)
			|| \Bitrix\Intranet\Util::isExtranetUser($userId);
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