<?php
namespace Bitrix\Landing\Controller;

use Bitrix\Landing\Block;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Site\Type;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\BFile;
use Bitrix\Main\Error;
use Bitrix\Main\UI\Viewer;

class DiskFile extends Controller
{
	private const FILE_DOWNLOAD_URL = '/bitrix/services/main/ajax.php?' .
										'action=landing.api.diskFile.download&' .
										'fileId=#fileId#&blockId=#blockId#&scope=#scope#';

	public function getDefaultPreFilters(): array
	{
		// download link is opened by browser via GET, so the csrf filter is left to the base
		// controller, which adds it for POST only
		return [
			new ActionFilter\Authentication(),
			new ActionFilter\HttpMethod([
				ActionFilter\HttpMethod::METHOD_GET,
				ActionFilter\HttpMethod::METHOD_POST,
			]),
		];
	}

	/**
	 * Returns URL for download action.
	 *
	 * @param string $scope Scope code (site type).
	 * @param int $blockId Block id.
	 * @param int|null $fileId File id.
	 * @return string
	 */
	public static function getDownloadLink(string $scope, int $blockId, ?int $fileId = null): string
	{
		return str_replace(
			['#scope#', '#blockId#', '#fileId#'],
			[$scope, $blockId, $fileId ?: '#fileId#'],
			self::FILE_DOWNLOAD_URL . '&ver=' . time()
		);
	}

	/**
	 * Checks that current user is allowed to read the file linked in the block.
	 *
	 * @param string $scope Scope code (site type).
	 * @param int $blockId Block id.
	 * @param int $fileId File id.
	 * @return bool
	 */
	private function canReadFileInBlock(string $scope, int $blockId, int $fileId): bool
	{
		if (!$this->switchToScope($scope))
		{
			return false;
		}

		$landingId = Block::findVisibleLandingIdByFileInBlock($blockId, $fileId);

		return $landingId !== null && $this->canReadLanding($landingId);
	}

	/**
	 * Checks that current user is allowed to read the file linked in the landing or in one of its areas.
	 *
	 * @param string $scope Scope code (site type).
	 * @param int $landingId Landing id.
	 * @param int $fileId File id.
	 * @return bool
	 */
	private function canReadFileInLanding(string $scope, int $landingId, int $fileId): bool
	{
		if (!$this->switchToScope($scope))
		{
			return false;
		}

		// rights check is cheaper than the search through the content, so it goes first
		return $this->canReadLanding($landingId) && $this->landingContainsFile($landingId, $fileId);
	}

	/**
	 * Switches to the scope of the request. Public scopes are rejected: they keep no protected files.
	 *
	 * @param string $scope Scope code (site type).
	 * @return bool
	 */
	private function switchToScope(string $scope): bool
	{
		if (Type::isPublicScope($scope))
		{
			return false;
		}

		Type::setScope($scope);

		return true;
	}

	/**
	 * Checks that current user is allowed to read the landing.
	 *
	 * @param int $landingId Landing id.
	 * @return bool
	 */
	private function canReadLanding(int $landingId): bool
	{
		return $landingId > 0 && Rights::hasAccessForLanding($landingId, Rights::ACCESS_TYPES['read']);
	}

	/**
	 * Checks that landing or one of its areas contains link to the specified file.
	 *
	 * @param int $landingId Landing id.
	 * @param int $fileId File id.
	 * @return bool
	 */
	private function landingContainsFile(int $landingId, int $fileId): bool
	{
		if (Block::findVisibleLandingIdByFileInLanding($landingId, $fileId) !== null)
		{
			return true;
		}

		$landing = \Bitrix\Landing\Landing::createInstance($landingId, [
			'skip_blocks' => true,
			'check_permissions' => false,
		]);
		if (!$landing->exist())
		{
			return false;
		}

		foreach ($landing->getAreas() as $areaLandingId)
		{
			if (Block::findVisibleLandingIdByFileInLanding((int)$areaLandingId, $fileId) !== null)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Downloads file after permissions check.
	 *
	 * @param string $scope Scope code (site type).
	 * @param int $blockId Block id.
	 * @param int $fileId File id.
	 * @return BFile|null
	 */
	public function downloadAction(string $scope, int $blockId, int $fileId): ?BFile
	{
		if ($this->canReadFileInBlock($scope, $blockId, $fileId))
		{
			$fileInfo = \Bitrix\Landing\Connector\Disk::getFileInfo($fileId, false);
			if ($fileInfo)
			{
				return new BFile(\CFile::getFileArray($fileInfo['ID']), $fileInfo['NAME']);
			}
		}

		$this->addError(new Error('Access denied.'));
		return null;
	}

	/**
	 * Returns file info for viewer after check permissions.
	 *
	 * @param string $scope Scope code (site type).
	 * @param int $blockId Block id.
	 * @param int $fileId File id.
	 * @return array|null
	 */
	public function viewAction(string $scope, int $blockId, int $fileId): ?array
	{
		if ($this->canReadFileInBlock($scope, $blockId, $fileId))
		{
			$fileInfo = \Bitrix\Landing\Connector\Disk::getFileInfo($fileId, false);
			if ($fileInfo)
			{
				$urlToDownload = $this->getDownloadLink($scope, $blockId, $fileId);
				$attributes = Viewer\ItemAttributes::tryBuildByFileId($fileInfo['ID'], $urlToDownload);
				$attributes->setTitle($fileInfo['NAME']);
				return $attributes->getAttributes();
			}
		}

		$this->addError(new Error('Access denied.'));
		return null;
	}

	/**
	 * Returns raw file info.
	 *
	 * @param int $fileId File id.
	 * @param string $scope Scope code (site type).
	 * @param int $landingId Landing id.
	 * @return array|null
	 */
	public function infoAction(int $fileId, string $scope, int $landingId): ?array
	{
		if ($this->canReadFileInLanding($scope, $landingId, $fileId))
		{
			return \Bitrix\Landing\Connector\Disk::getFileInfo($fileId, false);
		}

		$this->addError(new Error('Access denied.'));
		return null;
	}
}
