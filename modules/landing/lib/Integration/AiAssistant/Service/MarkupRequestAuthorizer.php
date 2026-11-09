<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiSiteModeDisabledException;
use Bitrix\Landing\Integration\AiAssistant\Exception\MarkupRequestRefusedException;
use Bitrix\Landing\Markup\Submission\EditTargetResolver;
use Bitrix\Landing\Markup\UploadBuffer;
use Bitrix\Landing\Rights;
use Bitrix\Main\Result;

/**
 * The checks of a markup file request shared by the put_markup_file tool and the landing.markup.* REST
 * methods: the caller of the REST bridge, the availability of the markup generation and the rights on
 * the target of an upload buffer. Every refusal is a MarkupRequestRefusedException with a string code.
 */
class MarkupRequestAuthorizer
{
	// SignedUserAuth::AUTH_TYPE of aiassistant: only the bridge of Marta comes in with it
	public const SIGNED_USER_AUTH_TYPE = 'aiassistant_signed_user';

	private const OPENING_MODES = [UploadBuffer::MODE_CREATE, UploadBuffer::MODE_UPDATE];

	/**
	 * @throws MarkupRequestRefusedException
	 */
	public function assertBridgeCaller(?string $authType): void
	{
		if ($authType !== self::SIGNED_USER_AUTH_TYPE)
		{
			throw new MarkupRequestRefusedException(
				'The method is available to the AI assistant bridge only.',
				MarkupRequestRefusedException::ACCESS_DENIED,
			);
		}
	}

	/**
	 * The selected generation answers first, then the common gate of the markup tools and the product.
	 *
	 * @throws MarkupRequestRefusedException
	 */
	public function assertMarkupAvailable(): void
	{
		try
		{
			(new AiSiteModeGuard())->assertModeSelected(CopilotManager::AI_SITES_MODE_MARKUP);
		}
		catch (AiSiteModeDisabledException $exception)
		{
			throw new MarkupRequestRefusedException(
				$exception->getMessage(),
				MarkupRequestRefusedException::MODE_DISABLED,
				$exception,
			);
		}

		if (!$this->isMarkupRuntimeAvailable())
		{
			throw new MarkupRequestRefusedException(
				'AI sites markup tools are disabled.',
				MarkupRequestRefusedException::DISABLED,
			);
		}
	}

	/**
	 * A creation needs the create right; an open update buffer needs the edit right on its page; an update
	 * buffer about to open names its binding only, and the edit right on the page the binding resolves to
	 * is asked of the edit target resolver. Any other refusal of the resolver is answered by the handler.
	 *
	 * @param array{mode: string, bindingId: ?int, pageId: ?int} $target
	 *
	 * @throws MarkupRequestRefusedException
	 */
	public function assertPutTargetPermitted(array $target, int $userId): void
	{
		if (!in_array($target['mode'], self::OPENING_MODES, true))
		{
			throw new MarkupRequestRefusedException(
				'The upload buffer does not belong to a site creation or a page change.',
				MarkupRequestRefusedException::BAD_REQUEST,
			);
		}

		if ($target['mode'] === UploadBuffer::MODE_CREATE)
		{
			if (!$this->canCreateLandingSites())
			{
				throw $this->createAccessDenied('Access denied. You do not have permission to create landing sites.');
			}

			return;
		}

		if ($target['pageId'] !== null)
		{
			if (!$this->canEditPage($target['pageId']))
			{
				throw $this->createAccessDenied(EditTargetResolver::ACCESS_DENIED_MESSAGE);
			}

			return;
		}

		// the handler refuses an update target without its binding
		if ($target['bindingId'] === null)
		{
			return;
		}

		$resolved = $this->resolveEditTarget($target['bindingId'], $userId);
		if ($resolved->isSuccess())
		{
			return;
		}

		$refusal = $resolved->getErrors()[0];
		if ((string)$refusal->getCode() === EditTargetResolver::ERROR_ACCESS_DENIED)
		{
			throw $this->createAccessDenied($refusal->getMessage());
		}
	}

	protected function isMarkupRuntimeAvailable(): bool
	{
		return CopilotManager::isAiSitesMarkupEnabled()
			&& (new AiSiteRuntimeAvailabilityGuard())->checkProductAvailability()->isAvailable()
		;
	}

	protected function canCreateLandingSites(): bool
	{
		return Rights::hasAdditionalRight(Rights::ADDITIONAL_RIGHTS['create']);
	}

	protected function canEditPage(int $pageId): bool
	{
		return Rights::hasAccessForLanding($pageId, Rights::ACCESS_TYPES['edit']);
	}

	/**
	 * @return Result Success data: ['target' => SubmissionTarget].
	 */
	protected function resolveEditTarget(int $bindingId, int $userId): Result
	{
		return (new EditTargetResolver())->resolve($bindingId, $userId);
	}

	private function createAccessDenied(string $message): MarkupRequestRefusedException
	{
		return new MarkupRequestRefusedException($message, MarkupRequestRefusedException::ACCESS_DENIED);
	}
}
