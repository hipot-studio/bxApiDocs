<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkPasswordService;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Disk\Public\Provider\ExternalLinkProvider;
use Bitrix\Disk\Version;
use Bitrix\Main\ArgumentTypeException;

class ExternalLinkAccessCheckHandler extends ChainableAccessCheckHandler
{
	/**
	 * @param ExternalLinkProvider $externalLinkProvider
	 * @param ExternalLinkPasswordService $externalLinkPasswordService
	 * @param bool $shouldCheckPassword
	 */
	public function __construct(
		protected readonly ExternalLinkProvider $externalLinkProvider,
		protected readonly ExternalLinkPasswordService $externalLinkPasswordService,
		protected readonly bool $shouldCheckPassword,
		protected readonly ?ExternalLink $externalLink = null,
		protected readonly ?AttachedObject $attachedObject = null,
		protected readonly ?Version $version = null,
	)
	{
	}

	protected function doCheck(File $file): UnifiedLinkAccessLevel
	{
		$externalLink = $this->loadExternalLink($file);

		if (
			!$externalLink instanceof ExternalLink
			|| (
				$this->externalLink instanceof ExternalLink
				&& !$this->isLinkValidForFile($externalLink, $file)
			)
			|| (
				$this->shouldCheckPassword
				&& $externalLink->hasPassword()
				&& !$this->checkPassword($externalLink)
			)
		)
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		return match ($externalLink->getAccessRight()) {
			ExternalLinkTable::ACCESS_RIGHT_VIEW => UnifiedLinkAccessLevel::Read,
			ExternalLinkTable::ACCESS_RIGHT_EDIT => UnifiedLinkAccessLevel::Edit,
			default => UnifiedLinkAccessLevel::Denied,
		};
	}

	private function loadExternalLink(File $file): ?ExternalLink
	{
		// An injected link is already loaded and validated by ExternalLinkContext::resolve() in the same request.
		return $this->externalLink
			?? $this->externalLinkProvider->getForUnifiedLinkAccessCheck($file->getId())
		;
	}

	private function isLinkValidForFile(ExternalLink $externalLink, File $file): bool
	{
		$linkFile = $externalLink->getFile();

		return (
			!$externalLink->isExpired()
			&& $linkFile instanceof File
			&& (int)$linkFile->getRealObjectId() === (int)$file->getRealObjectId()
			&& $this->isAttachedObjectValidForFile($file)
			&& $this->isVersionValidForFile($file)
			&& (int)$externalLink->getVersionId() === (int)($this->version?->getId() ?? 0)
		);
	}

	private function isAttachedObjectValidForFile(File $file): bool
	{
		if (!$this->attachedObject instanceof AttachedObject)
		{
			return true;
		}

		$attachedFile = $this->attachedObject->getFile();

		return (
			$attachedFile instanceof File
			&& (int)$attachedFile->getRealObjectId() === (int)$file->getRealObjectId()
		);
	}

	private function isVersionValidForFile(File $file): bool
	{
		if (!$this->version instanceof Version)
		{
			return true;
		}

		$versionFile = $this->version->getObject();

		return (
			$versionFile instanceof File
			&& (int)$versionFile->getRealObjectId() === (int)$file->getRealObjectId()
		);
	}

	/**
	 * @param ExternalLink $externalLink
	 * @return bool
	 * @throws ArgumentTypeException
	 */
	protected function checkPassword(ExternalLink $externalLink): bool
	{
		return $this->externalLinkPasswordService->isConfirmed($externalLink);
	}
}
