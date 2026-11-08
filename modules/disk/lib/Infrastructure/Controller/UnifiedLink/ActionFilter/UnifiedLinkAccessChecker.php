<?php
declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessResult;
use Bitrix\Disk\Internal\Service\UnifiedLink\BaseObjectResolver;
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
	private ?UnifiedLinkAccessResult $accessResult = null;

	public function __construct(
		private readonly UnifiedLinkAccessLevel $targetAccessLevel,
		private readonly string $argumentName = 'file',
		private readonly bool $shouldReturn403 = true,
		private readonly bool $shouldCheckAll = false,
	)
	{
		parent::__construct();

		$serviceLocator = ServiceLocator::getInstance();
		$this->unifiedLinkAccessService = $serviceLocator->get(UnifiedLinkAccessService::class);
		$this->externalLinkProvider = $serviceLocator->get(ExternalLinkProvider::class);
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		$this->accessLevel = null;
		$this->accessResult = null;

		$arguments = $this->getAction()->getArguments();

		$baseObject = $arguments[$this->argumentName] ?? null;
		if (!($baseObject instanceof BaseObject))
		{
			return null;
		}

		$attachedObject = $arguments['attachedObject'] ?? null;
		$version = $arguments['version'] ?? null;

		$resolvedObject = BaseObjectResolver::resolve($baseObject, $version)->getRealObject();

		if ($this->shouldCheckAll)
		{
			$this->accessResult = $this->unifiedLinkAccessService->checkAll($resolvedObject, $attachedObject);
			$this->accessLevel = $this->accessResult->getMaxAccessLevel();
		}
		else
		{
			$this->accessLevel = $this->unifiedLinkAccessService->check($resolvedObject, $attachedObject);
		}

		if ($this->accessLevel->value < $this->targetAccessLevel->value)
		{
			$hasPassword = $this->hasPassword($resolvedObject);

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
	 * @return UnifiedLinkAccessResult|null
	 */
	public function getAccessResult(): ?UnifiedLinkAccessResult
	{
		return $this->accessResult;
	}

	/**
	 * @see \Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkRenderer::shouldRenderExternal
	 * @param BaseObject $baseObject
	 * @return bool
	 */
	protected function hasPassword(BaseObject $baseObject): bool
	{
		$externalLink = $this->externalLinkProvider->getForUnifiedLinkAccessCheck($baseObject->getId());

		return $externalLink instanceof ExternalLink && $externalLink->hasPassword();
	}
}
