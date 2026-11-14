<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller\DockedUploader;

use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\Engine\Controller;
use Bitrix\Disk\Internal\Service\DockedUpload\BizProcParameterDescriptorProvider;
use Bitrix\Disk\Internal\Service\DockedUpload\BizProcParametersValidator;
use Bitrix\Disk\Internal\Service\DockedUpload\FolderResolverService;
use Bitrix\Disk\Storage;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;

/**
 * Docked uploader BizProc parameter surface (API-03 + API-05).
 *
 * `validateParameters` (API-03) validates the required workflow parameters BEFORE the
 * commit starts (AC-051), so the frontend can block the upload until they are filled.
 * `getAutoStartParameters` (API-05) returns the descriptors of those parameters, so the
 * widget's own drawer (SC-012) knows what to collect. Both are read-path - no mutations.
 *
 * Both are gated by the write right on the TARGET the caller is about to commit into
 * ({@see self::canAccessTarget()}), not by the right to read the storage.
 */
final class BizProc extends Controller
{
	// Server-side feature kill switch for every action of this controller (#11).
	use DockedUploaderGate;

	private const SCENARIO_CREATE = 'create';
	private const SCENARIO_EDIT = 'edit';

	protected function getDefaultPreFilters()
	{
		return [
			new ActionFilter\Authentication(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Csrf(),
		];
	}

	/**
	 * Validates the required workflow parameters for a docked commit, bound to the
	 * (storage, scenario) pair (AC-078). storageId is authoritative: together with the
	 * storage's BizProc flag it decides whether any workflow parameters apply at all -
	 * a storage with BizProc disabled cannot require any. The target authorizes the call
	 * and takes no part in the verdict, so the composition stays a property of the pair.
	 *
	 * The request may still carry the `required` list of the API-03 contract; it is not
	 * bound here and takes no part in the verdict. The required composition comes from
	 * the actual autostart templates of the pair, so no caller - a restored set least of
	 * all - can widen or narrow what it will be measured against (P8.T3, AC-095).
	 *
	 * @param array<string, mixed> $parameters
	 */
	public function validateParametersAction(
		int $storageId,
		string $scenario,
		CurrentUser $currentUser,
		array $parameters = [],
		int $targetFolderId = 0,
		int $targetFileId = 0
	): ?array
	{
		$userId = (int)$currentUser->getId();

		// One refusal for a storage that does not exist and for one the caller may not
		// write to: splitting them turns storageId into an existence oracle.
		$storage = Storage::loadById($storageId, ['ROOT_OBJECT']);
		if (
			$storage === null
			|| !$this->canAccessTarget($storage, $scenario, $targetFolderId, $targetFileId, $userId)
		)
		{
			$this->addError(new Error('Access denied', 'DISK_DOCKED_ACCESS_DENIED'));

			return null;
		}

		/** @var BizProcParametersValidator $validator */
		$validator = ServiceLocator::getInstance()->get('disk.dockedUpload.bizProcParametersValidator');

		return $validator->validate($storageId, $scenario, $parameters, (bool)$storage->isEnabledBizProc());
	}

	/**
	 * Returns the autostart BizProc parameter descriptors for the (storage, scenario)
	 * pair (API-05), so the widget's own drawer can collect the required parameters
	 * before the deferred commit. Read-path - no mutations. storageId is authoritative:
	 * together with the storage's BizProc flag it decides whether any autostart parameters
	 * apply at all - a storage with BizProc disabled cannot expose any. The target
	 * authorizes the call; the descriptors themselves belong to the pair.
	 */
	public function getAutoStartParametersAction(
		int $storageId,
		string $scenario,
		CurrentUser $currentUser,
		int $targetFolderId = 0,
		int $targetFileId = 0
	): ?array
	{
		$userId = (int)$currentUser->getId();

		// One refusal for a storage that does not exist and for one the caller may not
		// write to: splitting them turns storageId into an existence oracle.
		$storage = Storage::loadById($storageId, ['ROOT_OBJECT']);
		if (
			$storage === null
			|| !$this->canAccessTarget($storage, $scenario, $targetFolderId, $targetFileId, $userId)
		)
		{
			$this->addError(new Error('Access denied', 'DISK_DOCKED_ACCESS_DENIED'));

			return null;
		}

		/** @var BizProcParameterDescriptorProvider $provider */
		$provider = ServiceLocator::getInstance()->get('disk.dockedUpload.bizProcParameterDescriptorProvider');

		$result = $provider->getAutoStartParameters($storageId, $scenario, (bool)$storage->isEnabledBizProc());
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * Whether the caller may be shown the workflow parameters of what they are about to
	 * commit. Both actions expose template names, parameter labels, options and defaults,
	 * and an empty validateParameters answers with the required composition, so the level
	 * is the product-wide one for workflow descriptors - StartWorkflow, which disk resolves
	 * to the write right on the document itself. The gate therefore asks the very permission
	 * the commit will ask: canAdd on the target folder of a `create`, canUpdate on the target
	 * file of an `edit`.
	 *
	 * Rights in disk are per object, so the storage root is NOT that permission: a user
	 * allowed to add into one sub-folder alone would lose the parameter drawer for an upload
	 * the commit is going to accept. The root stands in only for a caller naming no target
	 * at all, where it is still strictly stronger than reading the storage.
	 *
	 * An `edit` names its file only once the version target is known; the element awaiting a
	 * manual Replace carries its FOLDER alone, and is answered by that folder rather than by
	 * the root. What comes back on that branch is the `edit` side of the pair, which a
	 * `create` into the same folder would NOT disclose - the licence it takes is a different
	 * one: the answer is a property of the (storage, scenario) pair and is never personalised
	 * by file, so naming a folder cannot widen it towards a file the caller may not touch.
	 *
	 * A target that does not resolve, or that belongs to a storage other than the one named,
	 * is refused exactly like a target the caller may not write to, so the answer says nothing
	 * about what exists. An unknown scenario is gated the same way - the domain verdict on it
	 * belongs to the provider and the validator.
	 */
	private function canAccessTarget(
		Storage $storage,
		string $scenario,
		int $targetFolderId,
		int $targetFileId,
		int $userId
	): bool
	{
		$storageId = (int)$storage->getId();
		$folderResolver = $this->getFolderResolver();

		if ($scenario === self::SCENARIO_EDIT && $targetFileId > 0)
		{
			$file = File::loadById($targetFileId);

			return $file !== null
				&& (int)$file->getStorageId() === $storageId
				&& $folderResolver->canUpdateFile($file, $storage, $userId)
			;
		}

		if ($targetFolderId > 0)
		{
			$folder = Folder::loadById($targetFolderId);

			return $folder !== null
				&& (int)$folder->getStorageId() === $storageId
				&& $folderResolver->canAddToFolder($folder, $storage, $userId)
			;
		}

		$root = $storage->getRootObject();

		return $root !== null && $folderResolver->canAddToFolder($root, $storage, $userId);
	}

	private function getFolderResolver(): FolderResolverService
	{
		return ServiceLocator::getInstance()->get('disk.dockedUpload.folderResolver');
	}
}
