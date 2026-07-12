<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Service\UnifiedLink\Render;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\RenderResult;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkFileRenderer;
use Bitrix\Disk\Version;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\NotImplementedException;

class MobileUnifiedLinkRenderer
{
	/**
	 * @param File $file
	 * @param AttachedObject|null $attachedObject
	 * @param Version|null $version
	 * @param CurrentUser $currentUser
	 * @return RenderResult
	 */
	public static function render(
		File $file,
		?AttachedObject $attachedObject,
		?Version $version,
		CurrentUser $currentUser,
	): RenderResult
	{
		$renderer = new UnifiedLinkFileRenderer(
			file: $file,
			attachedObject: $attachedObject,
			version: $version,
			currentUser: $currentUser,
		);

		return $renderer->render();
	}

	/**
	 * @param string $uniqueCode
	 * @param int $attachedObjectId
	 * @param int $versionId
	 * @param CurrentUser $currentUser
	 * @return RenderResult
	 * @throws NotImplementedException
	 */
	public static function renderByUniqueCode(
		string $uniqueCode,
		int $attachedObjectId,
		int $versionId,
		CurrentUser $currentUser,
	): RenderResult
	{
		$file = File::loadByUniqueCode($uniqueCode);
		if (!$file)
		{
			return new RenderResult(UnifiedLinkFileRenderer::renderAccessDeniedPage(), 403);
		}

		$attachedObject = AttachedObject::loadById($attachedObjectId);
		$version = Version::loadById($versionId);

		return self::render($file, $attachedObject, $version, $currentUser);
	}

	/**
	 * @param int $fileId
	 * @param int $attachedObjectId
	 * @param int $versionId
	 * @param CurrentUser $currentUser
	 * @return RenderResult
	 * @throws NotImplementedException
	 */
	public static function renderByFileId(
		int $fileId,
		int $attachedObjectId,
		int $versionId,
		CurrentUser $currentUser,
	): RenderResult
	{
		$file = File::loadById($fileId)?->getRealObject();
		if (!$file)
		{
			return new RenderResult(UnifiedLinkFileRenderer::renderAccessDeniedPage(), 403);
		}

		$attachedObject = AttachedObject::loadById($attachedObjectId);
		$version = Version::loadById($versionId);

		return self::render($file, $attachedObject, $version, $currentUser);
	}

	/**
	 * @param int $attachedObjectId
	 * @param int $versionId
	 * @param CurrentUser $currentUser
	 * @return RenderResult
	 * @throws NotImplementedException
	 */
	public static function renderByAttachedObject(
		int $attachedObjectId,
		int $versionId,
		CurrentUser $currentUser,
	): RenderResult
	{
		$attachedObject = AttachedObject::loadById($attachedObjectId);
		$file = $attachedObject?->getFile()?->getRealObject();
		if (!$file)
		{
			return new RenderResult(UnifiedLinkFileRenderer::renderAccessDeniedPage(), 403);
		}

		$version = Version::loadById($versionId);

		return self::render($file, $attachedObject, $version, $currentUser);
	}
}
