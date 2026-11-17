<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Service\UnifiedLink\Render;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\HtmlViewerPolicy;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\ClientContext;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\RenderResult;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkRenderer;
use Bitrix\Disk\Version;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\SystemException;

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
		$renderer = new UnifiedLinkRenderer(
			baseObject: $file,
			attachedObject: $attachedObject,
			version: $version,
			currentUser: $currentUser,
			client: ClientContext::Mobile,
		);

		return $renderer->render();
	}

	/**
	 * The entry of the html viewer, for a page that serves that one kind of target: a target outside the
	 * viewer policy is refused before a handler is chosen. Editing is closed by the policy itself, not by
	 * a level - it admits only TypeFile::KNOWN, and for those the factory hands out HtmlFileHandler,
	 * whose edit() is its view().
	 *
	 * No access level is passed on: the renderer is given one INSTEAD of the level of the reader, not as
	 * a cap over it, so naming a level here would answer a reader the file is closed to as if it were
	 * open. This entry has no prefilter in front of it - the refusal by rights is the renderer's own.
	 *
	 * @param File $file
	 * @param CurrentUser $currentUser
	 * @return RenderResult
	 */
	public static function renderHtmlViewer(File $file, CurrentUser $currentUser): RenderResult
	{
		if (!HtmlViewerPolicy::isViewable($file))
		{
			return new RenderResult(UnifiedLinkRenderer::renderAccessDeniedPage(), 403);
		}

		$renderer = new UnifiedLinkRenderer(
			baseObject: $file,
			attachedObject: null,
			version: null,
			currentUser: $currentUser,
			client: ClientContext::Mobile,
		);

		return $renderer->render();
	}

	/**
	 * @param string $uniqueCode
	 * @param CurrentUser $currentUser
	 * @return RenderResult
	 */
	public static function renderHtmlViewerByUniqueCode(string $uniqueCode, CurrentUser $currentUser): RenderResult
	{
		$file = File::loadByUniqueCode($uniqueCode);

		if (!$file)
		{
			return new RenderResult(UnifiedLinkRenderer::renderAccessDeniedPage(), 403);
		}

		return self::renderHtmlViewer($file, $currentUser);
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
			return new RenderResult(UnifiedLinkRenderer::renderAccessDeniedPage(), 403);
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
			return new RenderResult(UnifiedLinkRenderer::renderAccessDeniedPage(), 403);
		}

		$securityContext = $file->getStorage()?->getSecurityContext($currentUser);

		if (!$securityContext || !$file->canRead($securityContext))
		{
			return new RenderResult(UnifiedLinkRenderer::renderAccessDeniedPage(), 403);
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
	 * @throws SystemException
	 */
	public static function renderByAttachedObject(
		int $attachedObjectId,
		int $versionId,
		CurrentUser $currentUser,
	): RenderResult
	{
		$attachedObject = AttachedObject::loadById($attachedObjectId);

		if (!$attachedObject?->canRead((int)$currentUser->getId()))
		{
			return new RenderResult(UnifiedLinkRenderer::renderAccessDeniedPage(), 403);
		}

		$file = $attachedObject?->getFile()?->getRealObject();

		if (!$file)
		{
			return new RenderResult(UnifiedLinkRenderer::renderAccessDeniedPage(), 403);
		}

		$version = Version::loadById($versionId);

		return self::render($file, $attachedObject, $version, $currentUser);
	}
}
