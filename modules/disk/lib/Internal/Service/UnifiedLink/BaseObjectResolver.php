<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Version;

class BaseObjectResolver
{
	/**
	 * @param BaseObject $baseObject
	 * @param Version|null $version
	 * @return BaseObject
	 */
	public static function resolve(BaseObject $baseObject, ?Version $version = null): BaseObject
	{
		$objectFromVersion = self::resolveVersion($baseObject, $version)?->getObject();

		return $objectFromVersion ?? $baseObject;
	}

	public static function resolveVersion(BaseObject $baseObject, ?Version $version = null): ?Version
	{
		if (!$baseObject instanceof File || $version === null)
		{
			return null;
		}

		return (int)$version->getObjectId() === (int)$baseObject->getRealObjectId() ? $version : null;
	}

	/**
	 * An attachedId from a unified-link query may only address an attachment of the requested file.
	 */
	public static function resolveAttachedObject(
		BaseObject $baseObject,
		?AttachedObject $attachedObject = null,
	): ?AttachedObject
	{
		if (!$baseObject instanceof File)
		{
			return null;
		}

		$attachedFile = $attachedObject?->getFile();
		if (!$attachedFile instanceof File)
		{
			return null;
		}

		return (int)$attachedFile->getRealObjectId() === (int)$baseObject->getRealObjectId()
			? $attachedObject
			: null
		;
	}
}
