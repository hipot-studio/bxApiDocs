<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Service\DockedUpload;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkUrl;
use Bitrix\Main\Context;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\HttpRequest;

/**
 * Who owns the file input and the drop zone of a folder page: the docked uploader or the legacy one.
 *
 * The page shell mounts the legacy uploader before the folder list component runs, so the shell cannot
 * read the answer from the component and has to reach the same one on its own - here.
 */
final class UploadOwner
{
	public function __construct(
		private readonly UnifiedLinkAccessService $unifiedLinkAccessService,
		private readonly UnifiedLinkUrl $unifiedLinkUrl,
	)
	{
	}

	public static function getInstance(): self
	{
		return ServiceLocator::getInstance()->get(self::class);
	}

	public function isDockedOwner(?Folder $folder, ?HttpRequest $request = null): bool
	{
		if (!Configuration::isEnabledDockedUploader())
		{
			return false;
		}

		$request ??= $this->getCurrentRequest();

		// A view by unified link stays with the legacy uploader: the docked one knows nothing of the link token.
		return $request === null || !$this->isViewByUnifiedLink($folder, $request);
	}

	public function isViewByUnifiedLink(?Folder $folder, HttpRequest $request): bool
	{
		if (!$folder instanceof Folder)
		{
			return false;
		}

		$uls = $this->unifiedLinkUrl->getUlsFromRequest($request);

		if (!is_string($uls))
		{
			return false;
		}

		if (!$this->unifiedLinkAccessService->validateUlsForObjectId($folder->getId(), $uls))
		{
			return false;
		}

		$accessResult = $this->unifiedLinkAccessService->checkAll($folder);

		return $accessResult->getMaxAccessLevel()->canRead()
			&& !$accessResult->isOnlyPermissionSystemAccess()
		;
	}

	private function getCurrentRequest(): ?HttpRequest
	{
		$request = Context::getCurrent()?->getRequest();

		return $request instanceof HttpRequest ? $request : null;
	}
}
