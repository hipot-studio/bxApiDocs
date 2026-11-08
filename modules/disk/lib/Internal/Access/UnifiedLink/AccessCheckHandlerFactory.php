<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkPasswordService;
use Bitrix\Disk\Public\Provider\ExternalLinkProvider;
use Bitrix\Disk\User;
use Bitrix\Disk\Version;
use Bitrix\Main\Engine\CurrentUser;

final class AccessCheckHandlerFactory
{
	/** @var array<string, ChainableAccessCheckHandler> */
	private array $cacheForAuthorizedUser = [];
	/** @var array<string, AccessCheckHandler> */
	private array $cacheForAnonymousUser = [];

	/**
	 * @param ExternalLinkProvider $externalLinkProvider
	 * @param ExternalLinkPasswordService $externalLinkPasswordService
	 */
	public function __construct(
		protected readonly ExternalLinkProvider $externalLinkProvider,
		protected readonly ExternalLinkPasswordService $externalLinkPasswordService,
	)
	{
	}

	public function create(
		?AttachedObject $attachedObject = null,
		int $userId = 0,
		?ExternalLink $externalLink = null,
		?Version $version = null,
		?array $operationsByObjectId = null,
	): AccessCheckHandler
	{
		if ($userId === 0)
		{
			$userId = (int)CurrentUser::get()->getId();
		}

		$shouldCheckPassword = true;
		if ($userId > 0)
		{
			$user = User::loadById($userId);
			$shouldCheckPassword = $user->isExtranetUser() && !$user->isCollaber();
		}

		if ($externalLink instanceof ExternalLink)
		{
			return new ExternalLinkAccessCheckHandler(
				externalLinkProvider: $this->externalLinkProvider,
				externalLinkPasswordService: $this->externalLinkPasswordService,
				shouldCheckPassword: $shouldCheckPassword,
				externalLink: $externalLink,
				attachedObject: $attachedObject,
				version: $version,
			);
		}

		if ($userId > 0)
		{
			if (is_array($operationsByObjectId))
			{
				return $this->createForAuthorizedUser(
					userId: $userId,
					shouldCheckPassword: $shouldCheckPassword,
					attachedObject: $attachedObject,
					operationsByObjectId: $operationsByObjectId,
					version: $version,
				);
			}

			$cacheKey = $this->getCacheKey($shouldCheckPassword, $attachedObject, $userId, $version);

			return $this->cacheForAuthorizedUser[$cacheKey] ??= $this->createForAuthorizedUser(
				userId: $userId,
				shouldCheckPassword: $shouldCheckPassword,
				attachedObject: $attachedObject,
				version: $version,
			);
		}

		$cacheKey = $this->getCacheKey(true, $attachedObject, 0, $version);

		return $this->cacheForAnonymousUser[$cacheKey] ??= $this->getForExternalLink(true, $attachedObject, $version);
	}

	private function createForAuthorizedUser(
		int $userId,
		bool $shouldCheckPassword,
		?AttachedObject $attachedObject = null,
		?array $operationsByObjectId = null,
		?Version $version = null,
	): AccessCheckHandler
	{
		$externalLink = $this->getForExternalLink($shouldCheckPassword, $attachedObject, $version);
		$unifiedLink = $this->getForUnifiedLink($userId);
		$attachedObjectsAccessCheckHandler = new AttachedObjectsAccessCheckHandler($userId, $attachedObject);
		$permissionSystemAccessCheckHandler = new PermissionSystemAccessCheckHandler($userId, $operationsByObjectId);

		return $externalLink
			->append($unifiedLink)
			->append($permissionSystemAccessCheckHandler)
			->append($attachedObjectsAccessCheckHandler)
		;
	}

	/**
	 * The chain of handlers is cached here, never the answer it gives: doCheck() is handed the file on every
	 * call and binds the attachment to it, so the file is left out of the key and a result kept by a handler
	 * would hand the access of one file to the next.
	 */
	private function getCacheKey(
		bool $shouldCheckPassword,
		?AttachedObject $attachedObject = null,
		int $userId = 0,
		?Version $version = null,
	): string
	{
		$attachedObjectId = (int)$attachedObject?->getId();

		return 'attached_object_' . $attachedObjectId . '_user_' . $userId
			. '_scp_' . $shouldCheckPassword . '_version_' . (int)$version?->getId()
		;
	}

	/**
	 * @param bool $shouldCheckPassword
	 * @return AccessCheckHandler
	 */
	private function getForExternalLink(
		bool $shouldCheckPassword,
		?AttachedObject $attachedObject = null,
		?Version $version = null,
	): AccessCheckHandler
	{
		$externalLinkAccessCheckHandler = new ExternalLinkAccessCheckHandler(
			externalLinkProvider: $this->externalLinkProvider,
			externalLinkPasswordService: $this->externalLinkPasswordService,
			shouldCheckPassword: $shouldCheckPassword,
			attachedObject: $attachedObject,
			version: $version,
		);

		if ($shouldCheckPassword)
		{
			return $externalLinkAccessCheckHandler;
		}

		$externalLinkParentFoldersAccessCheckHandler = new ExternalLinkParentFoldersAccessCheckHandler();

		return $externalLinkParentFoldersAccessCheckHandler->append($externalLinkAccessCheckHandler);
	}

	/**
	 * The unified-link chain alone: the parent folders and the record of the object itself.
	 */
	public function getForUnifiedLink(int $userId): AccessCheckHandler
	{
		$unifiedLinkParentFoldersAccessCheckHandler = new UnifiedLinkParentFoldersAccessCheckHandler($userId);
		$unifiedLinkAccessCheckHandler = new UnifiedLinkAccessCheckHandler($userId);

		return $unifiedLinkParentFoldersAccessCheckHandler->append($unifiedLinkAccessCheckHandler);
	}
}
