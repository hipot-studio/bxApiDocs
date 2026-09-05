<?php

namespace Bitrix\DiskMobile\Controller;

use Bitrix\Disk\Controller\DataProviders\ChildrenDataProvider;
use Bitrix\Disk;
use Bitrix\Main\Error;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\UI\PageNavigation;

class Profile extends Folder
{
	private const ERROR_FOLDER_NOT_FOUND = 'DISKMOBILE_FOLDER_NOT_FOUND';
	private const ERROR_FOLDER_STORAGE_NOT_FOUND = 'DISKMOBILE_FOLDER_STORAGE_NOT_FOUND';

	protected function getChildren(
		int $id,
		array $order = [],
		bool $showRights = false,
		array $context = [],
		?string $search = null,
		?array $searchContext = null,
		?PageNavigation $pageNavigation = null,
	): ?Response\DataType\Page
	{
		$folder = $this->getFolder($id);
		if (!$folder)
		{
			return null;
		}

		$currentUser = $this->getCurrentUser();
		$childrenDataProvider = new ChildrenDataProvider();
		$searchScope = (string)$search !== '' ? 'subfolders' : 'currentFolder';
		$result = $childrenDataProvider->getChildren(
			$folder,
			$currentUser,
			$search,
			$searchScope,
			$showRights,
			$context,
			$order,
			$pageNavigation
		);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$data = $result->getData();

		return new Response\DataType\Page('children', $data['children'], $data['total']);
	}

	protected function getRights(int $id): ?array
	{
		$folder = $this->getFolder($id);
		if (!$folder)
		{
			return null;
		}

		$currentUser = $this->getCurrentUser();
		$securityContext = $folder->getStorage()?->getSecurityContext($currentUser);
		if (!$securityContext)
		{
			$this->addError(new Error(
				'Could not find storage for folder.',
				self::ERROR_FOLDER_STORAGE_NOT_FOUND,
				['folderId' => $id],
			));

			return null;
		}

		return [
			'canAdd' => $securityContext->canAdd($id),
		];
	}

	private function getFolder(int $id): ?Disk\Folder
	{
		$folder = Disk\Folder::loadById($id);
		if (!$folder)
		{
			$this->addError(new Error(
				'Could not find folder.',
				self::ERROR_FOLDER_NOT_FOUND,
				['folderId' => $id],
			));

			return null;
		}

		return $folder;
	}
}
