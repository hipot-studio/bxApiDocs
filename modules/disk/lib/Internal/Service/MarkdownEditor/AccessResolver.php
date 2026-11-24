<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\MarkdownEditor;

use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkUserAccessResolver;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkOpenPolicy;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkPasswordService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * The one answer to "who edits this Markdown file and may they", asked by the page, by every action of editing
 * and once more under the lock of the row of the object.
 *
 * A portal user edits by a right of their own or by the level "edit" of the unified link; the unified link is
 * taken into account only when the code of the file was presented, and only for employees and members of the
 * collab of the storage, as the html editor does. A guest edits by a live public link of the file itself with
 * the right to edit, its password passed in this session; a guest has no security context.
 */
final class AccessResolver
{
	public const ERROR_FEATURE_DISABLED = 'feature_disabled';
	public const ERROR_ACCESS_DENIED = 'access_denied';

	public function __construct(
		private readonly ?ExternalLinkPasswordService $passwordService = null,
		private readonly ?ExternalLinkOpenPolicy $openPolicy = null,
	)
	{
	}

	/**
	 * @return Result data['identity'] is {@see EditorIdentity}
	 */
	public function resolve(
		File $file,
		int $userId,
		?string $uniqueCode = null,
		?ExternalLink $link = null,
	): Result
	{
		$realFile = $file->getRealObject();
		if (!$realFile instanceof File || $realFile->isDeleted())
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}

		if (!EditPolicy::isEditableSource($realFile))
		{
			return $this->error(self::ERROR_FEATURE_DISABLED);
		}

		$identity = $link === null
			? $this->resolveByRights($realFile, $userId, $uniqueCode)
			: $this->resolveByExternalLink($realFile, $userId, $link)
		;
		if ($identity === null)
		{
			return $this->error(self::ERROR_ACCESS_DENIED);
		}

		return (new Result())->setData(['identity' => $identity]);
	}

	private function resolveByRights(File $file, int $userId, ?string $uniqueCode): ?EditorIdentity
	{
		if ($userId <= 0)
		{
			return null;
		}

		$byUnifiedLink = $this->hasMatchingUniqueCode($file, $uniqueCode)
			&& (new UnifiedLinkUserAccessResolver($userId))->canUseUnifiedLink($file)
		;
		$securityContext = $file->getStorage()?->getSecurityContext($userId, $byUnifiedLink);
		if ($securityContext === null || !$file->canUpdate($securityContext))
		{
			return null;
		}

		return new EditorIdentity(
			userId: $userId,
			file: $file,
			securityContext: $securityContext,
			uniqueCode: $byUnifiedLink ? $uniqueCode : null,
		);
	}

	private function resolveByExternalLink(File $file, int $userId, ExternalLink $link): ?EditorIdentity
	{
		// The model handed in may be stale: a deleted or changed link must not keep granting editing.
		$freshLink = ExternalLink::loadById((int)$link->getId());
		if (
			!$freshLink instanceof ExternalLink
			|| $freshLink->isExpired()
			|| !$freshLink->allowEdit()
			|| $freshLink->isSpecificVersion()
		)
		{
			return null;
		}

		$linkObject = $freshLink->getObject();
		if (
			!$linkObject instanceof File
			|| $linkObject->isDeleted()
			|| (int)$linkObject->getRealObjectId() !== (int)$file->getRealObjectId()
		)
		{
			return null;
		}

		if (!$this->getOpenPolicy()->canOpenByPortalPolicy($freshLink))
		{
			return null;
		}

		if ($freshLink->hasPassword() && !$this->getPasswordService()->isConfirmed($freshLink))
		{
			return null;
		}

		return new EditorIdentity(
			userId: $userId,
			file: $file,
			externalLink: $freshLink,
		);
	}

	private function hasMatchingUniqueCode(File $file, ?string $uniqueCode): bool
	{
		return $uniqueCode !== null
			&& $uniqueCode !== ''
			&& hash_equals((string)$file->getUniqueCode(), $uniqueCode)
		;
	}

	private function getOpenPolicy(): ExternalLinkOpenPolicy
	{
		return $this->openPolicy ?? ServiceLocator::getInstance()->get(ExternalLinkOpenPolicy::class);
	}

	private function getPasswordService(): ExternalLinkPasswordService
	{
		return $this->passwordService ?? ServiceLocator::getInstance()->get(ExternalLinkPasswordService::class);
	}

	private function error(string $code): Result
	{
		return (new Result())->addError(new Error($code, $code));
	}
}
