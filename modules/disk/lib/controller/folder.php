<?php

namespace Bitrix\Disk\Controller;

use Bitrix\Disk;
use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\ZipNginx;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Engine\Response;

class Folder extends BaseObject
{
	public function configureActions()
	{
		$configureActions = parent::configureActions();
		$configureActions['downloadArchive'] = [
			'-prefilters' => [
				ActionFilter\Csrf::class,
			],
			'+prefilters' => [
				new ActionFilter\CloseSession(),
			]
		];

		return $configureActions;
	}

	public function getPrimaryAutoWiredParameter()
	{
		return new ExactParameter(Disk\Folder::class, 'folder', function($className, $id){
			return Disk\Folder::loadById($id);
		});
	}

	public function getAction(Disk\Folder $folder)
	{
		return $this->get($folder);
	}

	protected function get(Disk\BaseObject $folder)
	{
		if (!($folder instanceof Disk\Folder))
		{
			throw new ArgumentTypeException('folder', Disk\Folder::class);
		}

		$data = parent::get($folder);
		$data['folder'] = $data['object'];
		unset($data['object']);

		return $data;
	}

	public function getChildrenAction(Disk\Folder $folder, string $search = null, PageNavigation $pageNavigation = null): ?Response\DataType\Page
	{
		$storage = $folder->getStorage();
		if (!$storage)
		{
			$this->addError(new Error('Could not find storage for folder.'));

			return null;
		}

		$filter = [];
		if ($search)
		{
			$filter['%=NAME'] = str_replace('%', '', $search) . '%';
		}

		$limit = $pageNavigation?->getLimit() ?: 50;
		$offset = $pageNavigation?->getOffset() ?: 0;

		$securityContext = $storage->getSecurityContext($this->getCurrentUser()?->getId());
		$children = $folder->getChildren($securityContext, [
			'filter' => $filter,
			'limit' => $limit,
			'offset' => $offset,
		]);

		return new Response\DataType\Page('children', $children, 0);
	}

	public function copyToAction(Disk\Folder $folder, Disk\Folder $toFolder)
	{
		return $this->copyTo($folder, $toFolder);
	}

	public function moveToAction(Disk\Folder $folder, Disk\Folder $toFolder)
	{
		return $this->move($folder, $toFolder);
	}

	public function renameAction(Disk\Folder $folder, $newName, $autoCorrect = false)
	{
		return $this->rename($folder, $newName, $autoCorrect);
	}

	public function markDeletedAction(Disk\Folder $folder)
	{
		$this->markDeleted($folder);
	}

	public function deleteTreeAction(Disk\Folder $folder)
	{
		return $this->deleteFolder($folder);
	}

	public function restoreAction(Disk\BaseObject $folder)
	{
		return $this->restore($folder);
	}

	public function generateExternalLinkAction(Disk\Folder $folder)
	{
		if (!Bitrix24Manager::isFeatureEnabled('disk_manual_external_link'))
		{
			$this->addError(new Error('Could not generate external link. Feature is disabled by tarif.'));

			return null;
		}

		return $this->generateExternalLink($folder);
	}

	public function disableExternalLinkAction(Disk\Folder $folder)
	{
		return $this->disableExternalLink($folder);
	}
	
	public function getAllowedOperationsRightsAction(Disk\Folder $folder)
	{
		return $this->getAllowedOperationsRights($folder);
	}

	public function downloadArchiveAction(Disk\Folder $folder): ?ZipNginx\Archive
	{
		if (!ZipNginx\Configuration::isEnabled())
		{
			$this->addError(new Error('Work with mod_zip is disabled in module settings.'));

			return null;
		}

		$storage = $folder->getStorage();
		if (!$storage)
		{
			$this->addError(new Error('Could not find storage for folder.'));

			return null;
		}

		$securityContext = $storage->getSecurityContext($this->getCurrentUser()?->getId());

		return ZipNginx\Archive::createFromFolder($folder, $securityContext);
	}
}