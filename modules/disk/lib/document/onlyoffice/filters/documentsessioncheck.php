<?php

namespace Bitrix\Disk\Document\OnlyOffice\Filters;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\GuestUser;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkUrl;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\Storage;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class DocumentSessionCheck extends ActionFilter\Base
{
	private UnifiedLinkAccessService $unifiedLinkAccessService;
	private UnifiedLinkUrl $unifiedLinkUrl;
	/** @var \Closure */
	private $getHashValue;
	/** @var bool */
	private $checkHash = false;
	/** @var bool */
	private $strictCheckRight = false;
	/** @var bool */
	private $ownerCheck = false;

	public function __construct()
	{
		parent::__construct();

		$serviceLocator = ServiceLocator::getInstance();
		$this->unifiedLinkAccessService = $serviceLocator->get(UnifiedLinkAccessService::class);
		$this->unifiedLinkUrl = $serviceLocator->get(UnifiedLinkUrl::class);
	}

	public function enableHashCheck(\Closure $getHashValue): self
	{
		$this->getHashValue = $getHashValue;
		$this->checkHash = true;

		return $this;
	}

	public function enableOwnerCheck(): self
	{
		$this->ownerCheck = true;

		return $this;
	}

	public function enableStrictCheckRight(): self
	{
		$this->strictCheckRight = true;

		return $this;
	}

	public function onBeforeAction(Event $event)
	{
		foreach ($this->getAction()->getArguments() as $argument)
		{
			if (!($argument instanceof DocumentSession))
			{
				continue;
			}

			$documentSession = $argument;

			$currentUserId = $this->getAction()->getCurrentUser()->getId();
			$currentUserId = $currentUserId? (int)$currentUserId : null;

			$isGuestSession = GuestUser::isGuestUserId($documentSession->getUserId()) && !$currentUserId;
			if (!$isGuestSession && $this->ownerCheck)
			{
				if ($currentUserId === null || !$documentSession->belongsToUser($currentUserId))
				{
					$this->addError(new Error('Could not operate by session from another user.'));

					return new EventResult(EventResult::ERROR, null, null, $this);
				}
			}
			if ($this->checkHash)
			{
				$hash = ($this->getHashValue)();
				if ($documentSession->getExternalHash() !== $hash)
				{
					$this->addError(new Error('Invalid document session id.'));

					return new EventResult(EventResult::ERROR, null, null, $this);
				}
			}
			if (!$isGuestSession && $this->strictCheckRight)
			{
				$securityContext = $this->getSecurityContext($documentSession, $this->getAction()->getCurrentUser());

				if (!$securityContext)
				{
					$this->addError(new Error('Could not get security context for document session.'));
				}
				elseif ($documentSession->isView() && !$documentSession->canRead($securityContext))
				{
					$this->addError(new Error('Could not read session.'));
				}
				elseif ($documentSession->isEdit() && !$documentSession->canEdit($securityContext))
				{
					$this->addError(new Error('Could not edit document by session.'));
				}

				if ($this->getErrors())
				{
					return new EventResult(EventResult::ERROR, null, null, $this);
				}
			}
		}

		return null;
	}

	private function getSecurityContext(DocumentSession $documentSession, CurrentUser $currentUser): ?SecurityContext
	{
		$storage = $documentSession->getFile()?->getStorage();

		if (!$storage instanceof Storage)
		{
			return null;
		}

		return $storage->getSecurityContext($currentUser, $this->shouldCheckUnifiedLink($documentSession));
	}

	private function shouldCheckUnifiedLink(DocumentSession $documentSession): bool
	{
		$file = $documentSession->getFile();

		if (!$file)
		{
			return false;
		}

		$uls = $this->unifiedLinkUrl->getUlsFromRequest($this->getAction()->getController()->getRequest());

		if (!is_string($uls) || $uls === '')
		{
			return false;
		}

		return $this->unifiedLinkAccessService->validateUlsForObjectId($file->getId(), $uls);
	}
}
