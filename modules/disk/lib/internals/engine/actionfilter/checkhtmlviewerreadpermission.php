<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internals\Engine\ActionFilter;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\HtmlViewer\PublicDomainAccessContext;
use Bitrix\Disk\Internal\Service\HtmlViewer\PublicDomainAccessTokenService;
use Bitrix\Disk\Internal\Service\HtmlViewerPolicy;
use Bitrix\Disk\User;
use Bitrix\Disk\Version;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class CheckHtmlViewerReadPermission extends CheckReadPermission
{
	private readonly PublicDomainAccessTokenService $accessTokenService;
	private ?bool $publicDomainAccessGranted = null;

	public function __construct(?PublicDomainAccessTokenService $accessTokenService = null)
	{
		parent::__construct();

		$this->accessTokenService = $accessTokenService ?? new PublicDomainAccessTokenService();
	}

	public function onBeforeAction(Event $event)
	{
		$accessToken = $this->action
			->getController()
			->getRequest()
			->getQuery('access_token')
		;
		if ($accessToken === null)
		{
			return parent::onBeforeAction($event);
		}

		if ($this->publicDomainAccessGranted === null)
		{
			$this->publicDomainAccessGranted = is_string($accessToken)
				&& $this->checkPublicDomainAccess($accessToken)
			;
		}

		if ($this->publicDomainAccessGranted)
		{
			return null;
		}

		$this->addReadError();

		return new EventResult(EventResult::ERROR, null, null, $this);
	}

	private function checkPublicDomainAccess(string $accessToken): bool
	{
		$context = $this->accessTokenService->decodeToken($accessToken);
		if ($context === null)
		{
			return false;
		}

		foreach ($this->action->getArguments() as $argument)
		{
			if ($argument instanceof File)
			{
				return $this->checkFile($argument, $context);
			}

			if ($argument instanceof Version)
			{
				return $this->checkVersionSource($argument, $context);
			}

			if ($argument instanceof AttachedObject)
			{
				return $this->checkAttachedObject($argument, $context);
			}
		}

		return false;
	}

	private function checkFile(File $file, PublicDomainAccessContext $context): bool
	{
		return $this->contextMatches(
			$context,
			$file,
			PublicDomainAccessContext::SOURCE_TYPE_FILE,
			(int)$file->getId(),
		)
			&& $this->isActiveUser($context->getUserId())
			&& HtmlViewerPolicy::isViewable($file)
			&& $this->unifiedLinkAccessService->check(
				$file,
				userId: $context->getUserId(),
			)->canRead()
		;
	}

	private function checkVersionSource(Version $version, PublicDomainAccessContext $context): bool
	{
		$file = $version->getObject();
		if (!$file instanceof File)
		{
			return false;
		}

		return $this->contextMatches(
			$context,
			$file,
			PublicDomainAccessContext::SOURCE_TYPE_VERSION,
			(int)$version->getId(),
		)
			&& $this->isActiveUser($context->getUserId())
			&& Configuration::isEnabledHtmlViewer()
			&& HtmlViewerPolicy::isViewableExtension($version->getExtension())
			&& $this->unifiedLinkAccessService->check(
				$file,
				userId: $context->getUserId(),
				version: $version,
			)->canRead()
		;
	}

	private function checkAttachedObject(
		AttachedObject $attachedObject,
		PublicDomainAccessContext $context,
	): bool
	{
		$file = $attachedObject->getFile();
		if (!$file instanceof File)
		{
			return false;
		}

		return $this->contextMatches(
			$context,
			$file,
			PublicDomainAccessContext::SOURCE_TYPE_ATTACHED_OBJECT,
			(int)$attachedObject->getId(),
		)
			&& $this->isActiveUser($context->getUserId())
			&& $this->isViewableAttachedObject($attachedObject, $file)
			&& $this->unifiedLinkAccessService->check(
				$file,
				attachedObject: $attachedObject,
				userId: $context->getUserId(),
			)->canRead()
		;
	}

	private function contextMatches(
		PublicDomainAccessContext $context,
		BaseObject $object,
		string $sourceType,
		int $sourceId,
	): bool
	{
		return $context->getObjectId() === (int)$object->getRealObjectId()
			&& $context->getSourceType() === $sourceType
			&& $context->getSourceId() === $sourceId
		;
	}

	private function isViewableAttachedObject(AttachedObject $attachedObject, File $file): bool
	{
		if (!$attachedObject->isSpecificVersion())
		{
			return HtmlViewerPolicy::isViewable($file);
		}

		$version = $attachedObject->getVersion();

		return $version instanceof Version
			&& Configuration::isEnabledHtmlViewer()
			&& HtmlViewerPolicy::isViewableExtension($version->getExtension())
		;
	}

	protected function isActiveUser(int $userId): bool
	{
		$user = User::loadById($userId);

		return $user instanceof User && $user->getActive() === 'Y';
	}
}
