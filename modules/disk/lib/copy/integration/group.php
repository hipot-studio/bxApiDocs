<?php
namespace Bitrix\Disk\Copy\Integration;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContextStack;
use Bitrix\Main\Config\Option;
use Bitrix\Socialnetwork\Copy\Integration\Feature;

class Group implements Feature
{
	private $executiveUserId;
	private $features = [];
	private $securityContext;

	const MODULE_ID = "disk";
	const QUEUE_OPTION = "DiskGroupQueue";
	const CHECKER_OPTION = "DiskGroupChecker_";
	const STEPPER_OPTION = "DiskGroupStepper_";
	const STEPPER_CLASS = GroupStepper::class;
	const ERROR_OPTION = "DiskGroupError_";

	public function __construct($executiveUserId = 0, array $features = [])
	{
		$this->executiveUserId = $executiveUserId;
		$this->features = $features;
		$this->securityContext = Driver::getInstance()->getFakeSecurityContext();
	}

	public function copy($groupId, $copiedGroupId)
	{
		$this->addToQueue($copiedGroupId);

		$storage = Driver::getInstance()->getStorageByGroupId($groupId);
		$targetStorage = Driver::getInstance()->getStorageByGroupId($copiedGroupId);

		if ($storage && $targetStorage)
		{
			if ($storage->isEnabledShowExtendedRights())
			{
				$targetStorage->enableShowExtendedRights();
			}

			$rootFolder = $storage->getRootObject();
			$targetRootFolder = $targetStorage->getRootObject();

			$this->setRootRights($groupId, $copiedGroupId, $rootFolder, $targetRootFolder);

			$mapFolderIds = [];
			$this->copyFolders(
				$groupId,
				$copiedGroupId,
				$rootFolder,
				$targetRootFolder,
				$mapFolderIds
			);

			if (!in_array("onlyFolders", $this->features))
			{
				Option::set(self::MODULE_ID, self::CHECKER_OPTION.$copiedGroupId, "Y");

				$dataToCopy = [
					"groupId" => $groupId,
					"copiedGroupId" => $copiedGroupId,
					"executiveUserId" => $this->executiveUserId,
					'auditOperationId' => AuditOperationContextStack::createOperationId(),
					"mapFolderIds" => $mapFolderIds
				];
				Option::set(
					self::MODULE_ID,
					self::STEPPER_OPTION . $copiedGroupId,
					serialize($dataToCopy)
				);

				$agent = \CAgent::getList([], [
					"MODULE_ID" => self::MODULE_ID,
					"NAME" => GroupStepper::class."::execAgent();"
				])->fetch();
				if (!$agent)
				{
					GroupStepper::bind(1);
				}
			}
		}
	}

	private function copyFolders(
		int $groupId,
		int $copiedGroupId,
		Folder $folder,
		Folder $targetFolder,
		array &$mapFolderIds
	): void
	{
		$mapFolderIds[$folder->getId()] = $targetFolder->getId();

		$children = $folder->getChildren($this->securityContext);
		foreach ($children as $child)
		{
			if ($child instanceof Folder)
			{
				$newFolder = $targetFolder->addSubFolder(
					[
						'NAME' => $child->getName(),
						'CREATED_BY' => $this->executiveUserId,
					],
					$this->prepareCopiedRights($groupId, $copiedGroupId, $child),
				);
				if ($newFolder)
				{
					$this->copyFolders(
						$groupId,
						$copiedGroupId,
						$child,
						$newFolder,
						$mapFolderIds,
					);
				}
			}
		}
	}

	/**
	 * The root of the target storage already exists with the rights of the new group, so replacing them
	 * takes a set(). Every folder below it is created by copyFolders() with its rights in hand, which is
	 * why this is the only set() of the folder walk; the rights of files go through GroupStepper.
	 */
	private function setRootRights(
		int $groupId,
		int $copiedGroupId,
		BaseObject $rootFolder,
		BaseObject $targetRootFolder,
	): void
	{
		Driver::getInstance()->getRightsManager()->set(
			$targetRootFolder,
			$this->prepareCopiedRights($groupId, $copiedGroupId, $rootFolder),
		);
	}

	private function prepareCopiedRights(
		int $groupId,
		int $copiedGroupId,
		BaseObject $sourceFolder,
	): array
	{
		$sourceRights = Driver::getInstance()->getRightsManager()->getSpecificRights($sourceFolder);

		$copiedRights = [];
		foreach ($sourceRights as $right)
		{
			// The object of the copy is not known to a right until it is set on one, and its own id belongs
			// to the row of the source.
			unset($right['ID'], $right['OBJECT_ID']);

			// A domain names the sharing of the source that made the rule, and the copy has no sharing of
			// its own: kept here, the rule of the copy would answer for an object of the source project.
			unset($right['DOMAIN']);

			$right['ACCESS_CODE'] = $this->prepareAccessCodeByCopiedGroup(
				$groupId,
				$copiedGroupId,
				$right['ACCESS_CODE']
			);

			$copiedRights[] = $right;
		}

		return $copiedRights;
	}

	private function prepareAccessCodeByCopiedGroup(
		int $groupId,
		int $copiedGroupId,
		string $accessCode
	): string
	{
		if (mb_substr($accessCode, 0, 2) === 'SG')
		{
			[$code,] = explode('_', $accessCode);
			if ($groupId == mb_substr($code, 2))
			{
				$accessCode = str_replace($groupId, $copiedGroupId, $accessCode);
			}
		}

		return $accessCode;
	}

	private function addToQueue(int $copiedGroupId)
	{
		$option = Option::get(self::MODULE_ID, self::QUEUE_OPTION, "");
		$option = ($option !== "" ? unserialize($option, ['allowed_classes' => false]) : []);
		$option = (is_array($option) ? $option : []);

		$option[] = $copiedGroupId;
		Option::set(self::MODULE_ID, self::QUEUE_OPTION, serialize($option));
	}
}
