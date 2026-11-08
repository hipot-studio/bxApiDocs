<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Action;

use Bitrix\AiAssistant\Core\Dto\HintButton;
use Bitrix\AiAssistant\Core\Dto\HintDto;
use Bitrix\AiAssistant\Core\Service\AiBot;
use Bitrix\AiAssistant\Trigger\Action\BaseAction;
use Bitrix\AiAssistant\Trigger\Enum\RestartStatusEnum;
use Bitrix\AiAssistant\Trigger\Service\Dto\TriggerInitDto;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Service\CrmSetupConditionService;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

Loader::requireModule('aiassistant');

Loc::loadMessages(__FILE__);

/**
 * Proactive tooltip that offers the CRM administrator of a young portal to configure CRM
 * with the AI assistant.
 *
 * Every rejection reason has its own side effect: a user who can never match is dropped from
 * the candidates, a portal that outgrew the audience switches the whole trigger off, and a
 * temporary mismatch only moves the next attempt. Without that the condition would be
 * recalculated on every navigation hit.
 *
 * Global rejections are checked before the personal ones: switching the whole trigger off costs
 * one UPDATE per portal, while a personal rejection writes a row per user who reaches the stage.
 *
 * @internal
 */
final class CrmSetupOnboardingAction extends BaseAction
{
	public const TRIGGER_CODE = 'crm.onboarding-crm-setup';

	private const MAX_COUNT_STARTED = 30;
	private const HINT_ID = 'CrmSetupOnboardingHint';
	private const HINT_TTL = 30;

	/**
	 * Existing crm scenario, reused as is.
	 *
	 * @see \Bitrix\Crm\Integration\AiAssistant\Scenario\EmptyCrmSetupScenario
	 */
	private const SCENARIO_ID = 'empty_crm_setup';

	private const ANALYTICS_COMMAND = 'sendAnalyticsEvent';

	/** Analytics type of the prompt button => phrase key of its caption */
	private const PROMPT_BUTTONS = [
		'crm_onboarding_first_steps' => 'CRM_ONBOARDING_TRIGGER_BUTTON_FIRST_STEPS',
		'crm_onboarding_help_with_settings' => 'CRM_ONBOARDING_TRIGGER_BUTTON_HELP_WITH_SETTINGS',
		'crm_onboarding_what_can_you_do' => 'CRM_ONBOARDING_TRIGGER_BUTTON_WHAT_CAN_YOU_DO',
	];

	public function __construct(
		private readonly CrmSetupConditionService $conditionService,
		private readonly AiBot $aiBot,
	)
	{
	}

	/**
	 * Returns the canonical code of the trigger, the single source for its registration.
	 */
	public static function getCode(): string
	{
		return self::TRIGGER_CODE;
	}

	protected function shouldRunAction(TriggerInitDto $triggerInitDto): bool
	{
		/*
		 * Both questions are asked first, before any of the rejections below writes per-user state
		 * or switches the portal-wide trigger row off.
		 *
		 * The engine gate alone would not be enough: crm.onboarding-crm-setup is absent from
		 * TooltipTriggerRegistry, so TooltipGate has no level for it and lets it through on a portal
		 * without the new agent, where the tooltip would lead to a chat that is not there.
		 *
		 * @see \Bitrix\AiAssistant\Config\TooltipGate
		 */
		if (!$this->isTooltipAllowed() || !$this->isBitrixGptV2TriggersEnabled())
		{
			return false;
		}

		if (!$this->conditionService->isNewPortal())
		{
			$this->getTriggerRepository()->inactivate($triggerInitDto->triggerId);

			return false;
		}

		if (!$this->conditionService->isCrmPresetPortal())
		{
			$this->rejectTriggerForUser($triggerInitDto);

			return false;
		}

		if (!$this->conditionService->isUserCrmAdmin($triggerInitDto->userId))
		{
			$this->setNewDateStart($triggerInitDto);

			return false;
		}

		if (!$this->hasRemainingAttempts($triggerInitDto))
		{
			$this->rejectTriggerForUser($triggerInitDto);

			return false;
		}

		/*
		 * Per-user condition: the configuration percentage is counted with the rights of this very
		 * user, so the portal-wide row must survive for the admins who still need the hint.
		 */
		if (!$this->conditionService->hasLowCrmConfigurationPercentage($triggerInitDto->userId))
		{
			$this->rejectTriggerForUser($triggerInitDto);

			return false;
		}

		return true;
	}

	protected function runAction(int $userId, int $progressId): bool
	{
		$userName = $this->getUserFirstName($userId);

		$this->aiBot->onTriggerHandler(
			new HintDto(
				userId: $userId,
				hintId: self::HINT_ID,
				title: $this->getTitle($userName),
				content: Loc::getMessage('CRM_ONBOARDING_TRIGGER_CONTENT') ?? '',
				message: $this->getStartMessage($userName),
				skipNotifyMessage: true,
				triggerProgressId: (string)$progressId,
				scenarioId: self::SCENARIO_ID,
				context: ['triggerCode' => self::getCode()],
				ttl: self::HINT_TTL,
				buttons: $this->getPromptButtons(),
			),
		);

		return true;
	}

	protected function needCompleteAfterRun(): bool
	{
		return false;
	}

	private function hasRemainingAttempts(TriggerInitDto $triggerInitDto): bool
	{
		$startCount = $this->getProgressRepository()->getTriggerStartCount(
			$triggerInitDto->triggerId,
			$triggerInitDto->userId,
		);

		return $startCount < self::MAX_COUNT_STARTED;
	}

	private function setNewDateStart(TriggerInitDto $triggerInitDto): void
	{
		$this->getRestartRepository()->update(
			$triggerInitDto,
			(new DateTime())->getTimestamp(),
			RestartStatusEnum::InProgress,
		);
	}

	private function getTitle(string $userName): string
	{
		if ($userName === '')
		{
			return Loc::getMessage('CRM_ONBOARDING_TRIGGER_TITLE_WITHOUT_USER_NAME') ?? '';
		}

		return Loc::getMessage('CRM_ONBOARDING_TRIGGER_TITLE', ['#USER_NAME#' => $userName]) ?? '';
	}

	/**
	 * The first message of the chat is carried by the hint itself: the tooltip click path runs
	 * the trigger with postInitialMessage disabled, so getMessage() is never asked for it.
	 */
	private function getStartMessage(string $userName): string
	{
		if ($userName === '')
		{
			return Loc::getMessage('CRM_ONBOARDING_TRIGGER_BOT_START_MESSAGE_WITHOUT_USER_NAME') ?? '';
		}

		return Loc::getMessage('CRM_ONBOARDING_TRIGGER_BOT_START_MESSAGE', ['#USER_NAME#' => $userName]) ?? '';
	}

	/**
	 * @return HintButton[]
	 */
	private function getPromptButtons(): array
	{
		return $this->createButtons(self::PROMPT_BUTTONS, self::ANALYTICS_COMMAND, []);
	}

	/**
	 * The broker comes from the crm container: autowiring it by class name would build a second
	 * instance with its own cache instead of the service registered as crm.service.broker.user.
	 */
	private function getUserFirstName(int $userId): string
	{
		$firstName = Container::getInstance()->getUserBroker()->getById($userId)['NAME'] ?? '';

		return is_string($firstName) ? trim($firstName) : '';
	}
}
