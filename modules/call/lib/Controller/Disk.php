<?php
namespace Bitrix\Call\Controller;

use Bitrix\Disk\Controller\File;
use Bitrix\Disk\Controller\Content;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Loader;

/**
 * "Proxy" controller to Disk controller actions.
 *
 * @internal
 */
class Disk extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return [
			new ActionFilter\HttpMethod(['POST']),
			new Filter\Authorization(),
			new Filter\DiskFolderAccessCheck(),
		];
	}

	public function configureActions(): array
	{
		return [
			'upload' => [
				'-prefilters' => [
					ActionFilter\Csrf::class,
					ActionFilter\Authentication::class
				],
			],
			'commit' => [
				'-prefilters' => [
					ActionFilter\Csrf::class,
					ActionFilter\Authentication::class
				],
			],
		];
	}

	protected function processBeforeAction(Action $action): bool
	{
		if (!Loader::includeModule('disk'))
		{
			return false;
		}

		return parent::processBeforeAction($action);
	}

	/**
	 * @restMethod call.disk.upload
	 * @param $filename
	 * @param $token
	 */
	public function uploadAction($filename, $token = null)
	{
		$params = [
			'filename' => $filename,
			'token' => $token,
		];
		$this->setScope(Controller::SCOPE_REST);

		return $this->forward(new Content(), 'upload', $params);
	}

	/**
	 * @restMethod call.disk.commit
	 * @param $folderId
	 * @param $filename
	 * @param $contentId
	 * @param $generateUniqueName
	 */
	public function commitAction($folderId, $filename, $contentId, $generateUniqueName = false)
	{
		$params = [
			'folderId' => $folderId,
			'filename' => $filename,
			'contentId' => $contentId,
			'generateUniqueName' => $generateUniqueName,
		];

		$this->setScope(Controller::SCOPE_REST);

		return $this->forward(new File(), 'createByContent', $params);
	}
}