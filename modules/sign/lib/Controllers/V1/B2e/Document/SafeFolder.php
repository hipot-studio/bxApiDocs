<?php

namespace Bitrix\Sign\Controllers\V1\B2e\Document;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Attribute\ActionAccess;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Engine\Controller;
use Bitrix\Sign\Operation\Document\Safe\DeleteFolder;
use Bitrix\Sign\Operation\Document\Safe\MoveDocuments;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\Access\AccessibleItemType;

/**
 * Company safe folder CRUD and document relocation (Phase 3). Access is enforced by the
 * #[ActionAccess] attributes (item-aware where a concrete folder is targeted) plus in-body
 * checks for target placement and per-document moves. Every action is gated by the
 * safe folder grouping feature flag.
 */
class SafeFolder extends Controller
{
	/**
	 * Sentinel telling apart "targetFolderId not provided" from an explicit "No folder" (null/0)
	 * when deleting a folder: null/0 relocate documents to "No folder", a positive value to a
	 * folder, and the sentinel means the caller did not pass a target at all.
	 */
	private const TARGET_FOLDER_NOT_PROVIDED = -1;
	private const MOVE_DOCUMENTS_LIMIT = 1000;
	private const FOLDER_PAGE_LIMIT = 100;
	private const PEOPLE_PAGE_LIMIT = 100;

	#[ActionAccess(
		permission: ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_CREATE,
	)]
	public function createAction(string $title): array
	{
		if (!$this->isFeatureAvailable())
		{
			return [];
		}

		$title = trim($title);
		if ($title === '')
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_DOCUMENT_SAFE_FOLDER_ERROR_EMPTY_TITLE'),
				'EMPTY_TITLE',
			));

			return [];
		}

		$result = Container::instance()->getSafeFolderService()->create($title);
		if (!$result->isSuccess())
		{
			$this->addErrorsFromResult($result);

			return [];
		}

		return $result->getData();
	}

	#[ActionAccess(
		permission: ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_WRITE,
		itemType: AccessibleItemType::SAFE_FOLDER,
		itemIdOrUidRequestKey: 'folderId',
	)]
	public function renameAction(int $folderId, string $newTitle): array
	{
		if (!$this->isFeatureAvailable())
		{
			return [];
		}

		$newTitle = trim($newTitle);
		if ($newTitle === '')
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_DOCUMENT_SAFE_FOLDER_ERROR_EMPTY_TITLE'),
				'EMPTY_TITLE',
			));

			return [];
		}

		$result = Container::instance()->getSafeFolderService()->rename($folderId, $newTitle);
		if (!$result->isSuccess())
		{
			$this->addErrorsFromResult($result);

			return [];
		}

		return $result->getData();
	}

	#[ActionAccess(
		permission: ActionDictionary::ACTION_B2E_MY_SAFE,
	)]
	public function listByDepthLevelAction(
		int $depthLevel,
		int $limit = 50,
		int $offset = 0,
	): array
	{
		if (!$this->isFeatureAvailable())
		{
			return [];
		}

		$container = Container::instance();
		$accessService = $container->getSafeAccessService();
		$user = $accessService->getCurrentUserAccessModel();
		if ($depthLevel !== 0 || $user === null)
		{
			return [
				'folders' => [],
				'total' => 0,
				'nextOffset' => 0,
			];
		}

		$limit = min(max(1, $limit), self::FOLDER_PAGE_LIMIT);
		$offset = max(0, $offset);
		$listService = $container->getSafeListService();
		$folders = $listService->getWritableFoldersPage($user, $limit, $offset);
		$total = $listService->countWritableFolders($user);

		$items = [];
		foreach ($folders as $folder)
		{
			$items[] = [
				'id' => $folder->getId(),
				'title' => $folder->title,
			];
		}

		return [
			'folders' => $items,
			'total' => $total,
			'nextOffset' => $offset + count($items),
		];
	}

	#[ActionAccess(
		permission: ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_DELETE,
		itemType: AccessibleItemType::SAFE_FOLDER,
		itemIdOrUidRequestKey: 'folderId',
	)]
	public function deleteAction(int $folderId, ?int $targetFolderId = self::TARGET_FOLDER_NOT_PROVIDED): array
	{
		if (!$this->isFeatureAvailable())
		{
			return [];
		}

		$container = Container::instance();
		$safeFolderService = $container->getSafeFolderService();

		$folder = $safeFolderService->getById($folderId);
		if ($folder === null)
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_DOCUMENT_SAFE_FOLDER_ERROR_ALREADY_DELETED'),
				'FOLDER_ALREADY_DELETED',
			));

			return [];
		}

		$accessService = $container->getSafeAccessService();
		if (!$accessService->hasAccessToDelete($folder))
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_DOCUMENT_SAFE_FOLDER_ERROR_ACCESS_DENIED'),
				'ACCESS_DENIED',
			));

			return [];
		}

		$hasTargetFolder = $targetFolderId !== self::TARGET_FOLDER_NOT_PROVIDED;
		$normalizedTargetFolderId = ($targetFolderId === null || $targetFolderId <= 0) ? null : $targetFolderId;

		if ($hasTargetFolder && !$this->hasAccessToPlaceIntoTarget($normalizedTargetFolderId))
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_DOCUMENT_SAFE_FOLDER_ERROR_ACCESS_DENIED'),
				'ACCESS_DENIED',
			));

			return [];
		}

		$result = (new DeleteFolder($folderId, $normalizedTargetFolderId, $hasTargetFolder))->launch();
		if (!$result->isSuccess())
		{
			$this->addErrorsFromResult($result);

			return [];
		}

		return [];
	}

	/**
	 * @param list<int> $documentIds
	 */
	public function moveDocumentsAction(array $documentIds, ?int $targetFolderId = null): array
	{
		if (!$this->isFeatureAvailable())
		{
			return [];
		}

		if (count($documentIds) > self::MOVE_DOCUMENTS_LIMIT)
		{
			$this->addError(new Error('Too many documents', 'DOCUMENT_LIST_LIMIT_EXCEEDED'));

			return [];
		}

		$documentIds = array_values(array_unique(array_filter(
			array_map('intval', $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($documentIds))
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_DOCUMENT_SAFE_FOLDER_ERROR_EMPTY_DOCUMENT_LIST'),
				'EMPTY_DOCUMENT_LIST',
			));

			return [];
		}

		$result = (new MoveDocuments($documentIds, $targetFolderId))->launch();
		if (!$result->isSuccess())
		{
			$this->addErrorsFromResult($result);

			return [];
		}

		return $result->getData();
	}

	#[ActionAccess(
		permission: ActionDictionary::ACTION_B2E_MY_SAFE_FOLDER_READ,
		itemType: AccessibleItemType::SAFE_FOLDER,
		itemIdOrUidRequestKey: 'folderId',
	)]
	public function listPeopleAction(
		int $folderId,
		string $category,
		int $limit = 50,
		?int $afterUserId = null,
	): array
	{
		if (!$this->isFeatureAvailable())
		{
			return [];
		}

		if (!in_array($category, ['participants', 'representatives', 'senders'], true))
		{
			$this->addError(new Error('Unknown people category', 'INVALID_PEOPLE_CATEGORY'));

			return [];
		}

		$container = Container::instance();
		$folder = $container->getSafeFolderRepository()->getById($folderId);
		if ($folder === null || !$container->getSafeAccessService()->hasAccessToRead($folder))
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_DOCUMENT_SAFE_FOLDER_ERROR_ACCESS_DENIED'),
				'ACCESS_DENIED',
			));

			return [];
		}

		$page = $container->getMemberRepository()->listSafeFolderPeopleIds(
			$folderId,
			$category,
			min(max(1, $limit), self::PEOPLE_PAGE_LIMIT),
			$afterUserId,
		);
		$users = $container->getUserRepository()->getByIds($page['userIds']);
		$usersById = [];
		foreach ($users as $user)
		{
			$usersById[(int)$user->id] = $user;
		}

		$people = [];
		foreach ($page['userIds'] as $userId)
		{
			$user = $usersById[$userId] ?? null;
			if ($user === null)
			{
				continue;
			}

			$people[] = [
				'id' => $userId,
				'name' => \CUser::FormatName(\CSite::GetNameFormat(), [
					'NAME' => $user->name,
					'LAST_NAME' => $user->lastName,
					'SECOND_NAME' => $user->secondName,
				], true, false),
				'photo' => $user->personalPhotoId ? (string)\CFile::GetPath($user->personalPhotoId) : '',
			];
		}

		return [
			'people' => $people,
			'total' => $page['total'],
			'nextCursor' => $page['nextCursor'],
		];
	}

	private function hasAccessToPlaceIntoTarget(?int $targetFolderId): bool
	{
		// Relocating documents to "No folder" is the ambient default bucket and needs no extra
		// folder permission beyond the delete right already verified on the source folder.
		if ($targetFolderId === null)
		{
			return true;
		}

		return Container::instance()->getSafeAccessService()->hasAccessToEditFolderById($targetFolderId);
	}

	private function isFeatureAvailable(): bool
	{
		if (!Feature::instance()->isSafeFolderGroupingAllowed())
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_DOCUMENT_SAFE_FOLDER_ERROR_FEATURE_DISABLED'),
				'FEATURE_DISABLED',
			));

			return false;
		}

		return true;
	}
}
