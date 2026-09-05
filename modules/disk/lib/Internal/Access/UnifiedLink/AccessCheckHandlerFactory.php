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
	private ?ExternalLinkAccessCheckHandler $externalLinkAccessCheckHandler = null;

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
			$cacheKey = $this->getCacheKey($shouldCheckPassword, $attachedObject, $userId);

			return $this->cacheForAuthorizedUser[$cacheKey] ??= $this->createForAuthorizedUser(
				userId: $userId,
				shouldCheckPassword: $shouldCheckPassword,
				attachedObject: $attachedObject,
			);
		}

		return $this->externalLinkAccessCheckHandler ??= new ExternalLinkAccessCheckHandler(
			externalLinkProvider: $this->externalLinkProvider,
			externalLinkPasswordService: $this->externalLinkPasswordService,
			shouldCheckPassword: true,
		);
	}

	private function createForAuthorizedUser(
		int $userId,
		bool $shouldCheckPassword,
		?AttachedObject $attachedObject = null,
	): ChainableAccessCheckHandler
	{
		$unifiedLinkAccessCheckHandler = new UnifiedLinkAccessCheckHandler($userId);
		$attachedObjectsAccessCheckHandler = new AttachedObjectsAccessCheckHandler($userId, $attachedObject);
		$permissionSystemAccessCheckHandler = new PermissionSystemAccessCheckHandler($userId);

		return (new ExternalLinkAccessCheckHandler(
			externalLinkProvider: $this->externalLinkProvider,
			externalLinkPasswordService: $this->externalLinkPasswordService,
			shouldCheckPassword: $shouldCheckPassword,
		))
			->setNext($unifiedLinkAccessCheckHandler
				->setNext($permissionSystemAccessCheckHandler
					->setNext($attachedObjectsAccessCheckHandler),
				),
			)
		;
	}

	private function getCacheKey(
		bool $shouldCheckPassword,
		?AttachedObject $attachedObject = null,
		int $userId = 0,
	): string
	{
		$attachedObjectId = (int)$attachedObject?->getId();

		return 'attached_object_' . $attachedObjectId . '_user_' . $userId . '_scp_' . $shouldCheckPassword;
	}
}
