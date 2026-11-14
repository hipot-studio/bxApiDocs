<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\DocumentResolveContext;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\ProxyType\Group;

/**
 * Resolves the workgroup (project) a document open belongs to, for the vibeoffice cohort gate.
 *
 * The engine is a property of the file's storage, so the cohort is derived from the object's
 * storage entity type: only a workgroup ({@see Group}) storage yields a group id. Creation uses
 * the target folder's storage. Every other case — no target, object/storage not loaded, or a non-group
 * storage (personal disk, common drive, ...) — resolves to null (fail-closed), which the gate reads as
 * "not in the cohort".
 */
final class ProjectCohort
{
	/**
	 * Group id of the file or creation target, or null when it cannot be resolved to a workgroup.
	 *
	 * Fail-closed: no context / no file or creation target / object or storage not loaded / non-group storage
	 * all yield null. A single object lookup is performed only on the narrowed (allowlisted) path.
	 */
	public static function resolveGroupId(?DocumentResolveContext $context): ?int
	{
		$objectId = $context?->getObjectId();
		if ($objectId === null)
		{
			$targetFolderId = $context?->getTargetFolderId();
			$storage = $targetFolderId === null ? null : Folder::loadById($targetFolderId)?->getStorage();
		}
		else
		{
			$file = $context->getLoadedFile();
			if (!$file || (int)$file->getId() !== $objectId)
			{
				$file = File::loadById($objectId);
			}
			$storage = $file?->getStorage();
		}
		if ($storage === null)
		{
			return null;
		}

		if (!is_a($storage->getEntityType(), Group::class, true))
		{
			return null;
		}

		return (int)$storage->getEntityId();
	}

	/**
	 * Pure cohort decision: whether $groupId is covered by the $allowed workgroup ids.
	 *
	 * An empty allowlist means no narrowing and the feature stays portal-wide (allowed). Otherwise
	 * the group must have been resolved and be present in the allowlist.
	 *
	 * @param int[] $allowed
	 */
	public static function isAllowed(?int $groupId, array $allowed): bool
	{
		if (empty($allowed))
		{
			return true;
		}

		return $groupId !== null && in_array($groupId, $allowed, true);
	}
}
