<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkPasswordService;
use Bitrix\Disk\Internal\Service\UnifiedLink\BaseObjectResolver;
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

	protected function doCheck(BaseObject $baseObject): UnifiedLinkAccessLevel
	{
		$externalLink = $this->loadExternalLink($baseObject);

		if (
			!$externalLink instanceof ExternalLink
			|| !$this->isLinkValidForObject($externalLink, $baseObject)
			|| (
				$this->shouldCheckPassword
				&& $externalLink->hasPassword()
				&& !$this->checkPassword($externalLink)
			)
		)
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		return $this->mapAccessRightToAccessLevel($externalLink->getAccessRight());
	}

	protected function doCheckCollection(array $baseObjects): array
	{
		$objectIds = array_keys($baseObjects);
		$result = [];
		$externalLinks = $this->externalLinkProvider->getForUnifiedLinkAccessCheckCollection($objectIds);

		foreach ($externalLinks as $objectId => $externalLink)
		{
			if (!isset($baseObjects[$objectId]))
			{
				continue;
			}

			if (!$this->isLinkValidForObject($externalLink, $baseObjects[$objectId]))
			{
				continue;
			}

			if (
				$this->shouldCheckPassword
				&& $externalLink->hasPassword()
				&& !$this->checkPassword($externalLink)
			)
			{
				continue;
			}

			$result[$objectId] = $this->mapAccessRightToAccessLevel($externalLink->getAccessRight());
		}

		return $result;
	}

	protected function mapAccessRightToAccessLevel(int $accessRight): UnifiedLinkAccessLevel
	{
		return match ($accessRight)
		{
			ExternalLink::ACCESS_RIGHT_VIEW => UnifiedLinkAccessLevel::Read,
			ExternalLink::ACCESS_RIGHT_EDIT => UnifiedLinkAccessLevel::Edit,
			default => UnifiedLinkAccessLevel::Denied,
		};
	}

	protected function getAccessSource(): UnifiedLinkAccessSource
	{
		return UnifiedLinkAccessSource::ExternalLink;
	}

	private function loadExternalLink(BaseObject $baseObject): ?ExternalLink
	{
		return $this->externalLink
			?? $this->externalLinkProvider->getForUnifiedLinkAccessCheck($baseObject->getId())
		;
	}

	/**
	 * Every branch is bound the same way, whether the link was handed to the handler or found by the object
	 * itself: a link of another object, an expired one, and a link published for one revision opening
	 * anything but that revision all lose the access they carry.
	 */
	private function isLinkValidForObject(ExternalLink $externalLink, BaseObject $baseObject): bool
	{
		if ($baseObject instanceof File)
		{
			return $this->isLinkValidForFile($externalLink, $baseObject);
		}

		return (
			!$externalLink->isExpired()
			&& $this->isLinkPublishedForObject($externalLink, $baseObject)
			&& $this->attachedObject === null
			&& $this->version === null
			&& (int)$externalLink->getVersionId() === 0
		);
	}

	private function isLinkValidForFile(ExternalLink $externalLink, File $file): bool
	{
		return (
			!$externalLink->isExpired()
			&& $this->isLinkPublishedForFile($externalLink, $file)
			&& $this->isAttachedObjectValidForFile($file)
			&& $this->isVersionValidForFile($file)
			&& (int)$externalLink->getVersionId() === (int)($this->version?->getId() ?? 0)
		);
	}

	/**
	 * A link the handler finds by the object itself names the very row the caller already holds, so the
	 * object behind the link is read only when the two ids disagree: the case of a link handed to the
	 * handler from outside, which may be published for a symlink of the object or for another object.
	 */
	private function isLinkPublishedForObject(ExternalLink $externalLink, BaseObject $baseObject): bool
	{
		if ($this->isLinkPublishedForObjectId($externalLink, $baseObject))
		{
			return true;
		}

		$linkObject = $externalLink->getObject();

		return $linkObject instanceof BaseObject
			&& (int)$linkObject->getRealObjectId() === (int)$baseObject->getRealObjectId()
		;
	}

	private function isLinkPublishedForFile(ExternalLink $externalLink, File $file): bool
	{
		if ($this->isLinkPublishedForObjectId($externalLink, $file))
		{
			return true;
		}

		$linkFile = $externalLink->getFile();

		return $linkFile instanceof File
			&& (int)$linkFile->getRealObjectId() === (int)$file->getRealObjectId()
		;
	}

	private function isLinkPublishedForObjectId(ExternalLink $externalLink, BaseObject $baseObject): bool
	{
		$publishedObjectId = (int)$externalLink->getObjectId();

		return $publishedObjectId > 0 && $publishedObjectId === (int)$baseObject->getId();
	}

	private function isAttachedObjectValidForFile(File $file): bool
	{
		return $this->attachedObject === null
			|| BaseObjectResolver::resolveAttachedObject($file, $this->attachedObject) !== null
		;
	}

	private function isVersionValidForFile(File $file): bool
	{
		return $this->version === null
			|| BaseObjectResolver::resolveVersion($file, $this->version) !== null
		;
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
