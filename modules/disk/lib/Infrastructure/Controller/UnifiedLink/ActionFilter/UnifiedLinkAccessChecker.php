<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter;

use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\UnifiedLink\FileResolver;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Public\Provider\ExternalLinkProvider;
use Bitrix\Main\Context;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class UnifiedLinkAccessChecker extends Base
{
	private readonly UnifiedLinkAccessService $unifiedLinkAccessService;
	private readonly ExternalLinkProvider $externalLinkProvider;
	private ?UnifiedLinkAccessLevel $accessLevel = null;

	public function __construct(
		private readonly UnifiedLinkAccessLevel $targetAccessLevel,
		private readonly bool $shouldReturn403 = true,
	)
	{
		parent::__construct();

		$serviceLocator = ServiceLocator::getInstance();
		$this->unifiedLinkAccessService = $serviceLocator->get(UnifiedLinkAccessService::class);
		$this->externalLinkProvider = $serviceLocator->get(ExternalLinkProvider::class);
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		$arguments = $this->getAction()->getArguments();

		$file = $arguments['file'] ?? null;
		if (!($file instanceof File))
		{
			return null;
		}

		$attachedObject = $arguments['attachedObject'] ?? null;
		$version = $arguments['version'] ?? null;

		$resolvedFile = FileResolver::resolve($file, $version);
		$this->accessLevel = $this->unifiedLinkAccessService->check($resolvedFile, $attachedObject);

		if ($this->accessLevel->value < $this->targetAccessLevel->value)
		{
			$hasPassword = $this->hasPassword($file);

			if ($this->shouldReturn403)
			{
				Context::getCurrent()?->getResponse()?->setStatus(403);
			}

			$this->addError(new Error(
				message: 'Access level does not match the required level.',
				code: 'FORBIDDEN',
				customData: [
					'hasPassword' => $hasPassword,
				],
			));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}

	/**
	 * @return UnifiedLinkAccessLevel|null
	 */
	public function getAccessLevel(): ?UnifiedLinkAccessLevel
	{
		return $this->accessLevel;
	}

	/**
	 * @see \Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkFileRenderer::shouldRenderExternal
	 * @param File $file
	 * @return bool
	 */
	protected function hasPassword(File $file): bool
	{
		$externalLink = $this->externalLinkProvider->getForUnifiedLinkAccessCheck($file->getId());

		return $externalLink instanceof ExternalLink && $externalLink->hasPassword();
	}
}
