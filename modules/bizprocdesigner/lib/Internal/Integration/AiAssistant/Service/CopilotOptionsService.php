<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\AI\Container;
use Bitrix\AI\Services\CopilotAccessCheckerService;
use Bitrix\Main\Loader;

/**
 * Builds the CoPilot settings the editor screen hands to the frontend.
 */
class CopilotOptionsService
{
	private const MODULE_ID = 'bizprocdesigner';
	private const CATEGORY = 'bizprocdesigner';
	private const CONTEXT_ID_PREFIX = 'bizprocdesigner_template_description_';

	/**
	 * @param int|null $userId null when there is no current user; the CoPilot is never offered then.
	 *
	 * @return array{moduleId: string, category: string, contextId: string}|null
	 *         null when the user has no access to the CoPilot, or the ai module is missing or too old
	 *         to answer the access check.
	 */
	public function resolveOptions(?int $userId, int $templateId): ?array
	{
		// The access checker treats a zero id as "no user asked" and answers yes, so an unresolved user is
		// refused here instead of being handed over as one.
		if ($userId === null || $userId <= 0)
		{
			return null;
		}

		// Order matters: the ai container is unreachable while the module is not included.
		if (!$this->isAiModuleIncluded())
		{
			return null;
		}

		// An included module is not a guarantee of its services: an older ai build answers the container
		// request with an exception, and the editor screen must lose the button, not itself.
		try
		{
			$hasAccess = $this->hasCopilotAccess($userId);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!$hasAccess)
		{
			return null;
		}

		return [
			'moduleId' => self::MODULE_ID,
			'category' => self::CATEGORY,
			'contextId' => self::CONTEXT_ID_PREFIX . $templateId,
		];
	}

	protected function isAiModuleIncluded(): bool
	{
		return Loader::includeModule('ai');
	}

	protected function hasCopilotAccess(int $userId): bool
	{
		/** @var CopilotAccessCheckerService $accessChecker */
		$accessChecker = Container::init()->getItem(CopilotAccessCheckerService::class);

		return $accessChecker->canShowInFrontend($userId);
	}
}
