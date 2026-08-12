<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Landing\Copilot;
use Bitrix\Landing\Copilot\Services\NameService;
use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingService;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteChatAvailabilityService;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteChatContextService;
use Bitrix\Landing\Integration\AiAssistant\WidgetDataProvider;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Metrika;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

\CBitrixComponent::includeComponentClass('bitrix:landing.base');

class SiteCopilotComponent extends LandingBaseComponent
{
	private const INITIAL_PROMPT_FIELD = 'initial_prompt';
	private const INITIAL_PROMPT_TOKEN_FIELD = 'initial_prompt_token';
	private const INITIAL_PROMPT_SESSION_KEY = 'landing_ai_site_initial_prompt';
	private const INITIAL_PROMPT_SESSION_TTL = 60;
	private const INITIAL_PROMPT_MAX_LENGTH = 4000;
	private const AI_SITE_TRIGGER_TITLE_MESSAGE = 'LANDING_CMP_TOP_PANEL_SITE_NAME_MSGVER_1';
	private const AI_SITE_TRIGGER_INITIAL_MESSAGE = 'LANDING_SITE_COPILOT_TRIGGER_INITIAL_MESSAGE';
	private const AI_SITE_TRIGGER_ERROR_TRIGGER_INACTIVE = AiSiteChatAvailabilityService::ERROR_TRIGGER_INACTIVE;
	private const AI_SITE_TRIGGER_ERROR_TRIGGER_UNAVAILABLE = AiSiteChatAvailabilityService::ERROR_TRIGGER_UNAVAILABLE;
	private const AI_SITE_TRIGGER_ERROR_ENTITY_REQUIRED = AiSiteChatAvailabilityService::ERROR_ENTITY_REQUIRED;

	/**
	 * Base executable method.
	 * @return void
	 */
	public function executeComponent(): void
	{
		$this->init();

		// Analytic
		$metrika = new Metrika\Metrika(
			Metrika\Categories::SiteGeneration,
			Metrika\Events::open,
			Metrika\Tools::Ai,
		);
		if ($this->request('st_section'))
		{
			$metrika->setSection(Metrika\Sections::tryfrom((string)$this->request('st_section')));
		}

		$productAvailability = $this->getAiSiteChatAvailabilityService()->checkSitesAiProductAvailability();
		if (!$productAvailability->isAvailable())
		{
			$url = new Uri($this->arParams['PAGE_URL_SITES']);

			$productUnavailableReason = $productAvailability->getReasonCode();
			if (
				$productUnavailableReason !== AiSiteChatAvailabilityService::ERROR_PRODUCT_UNAVAILABLE
				&& $productUnavailableReason !== AiSiteChatAvailabilityService::ERROR_FEATURE_DISABLED_BY_OPTION
			)
			{
				$sliderCode = Copilot\Manager::getUnactiveSliderCode();
				if ($productUnavailableReason === AiSiteChatAvailabilityService::ERROR_FEATURE_LIMITED)
				{
					$sliderCode = Copilot\Manager::getLimitSliderCode();
				}

				$url->addParams([
					'feature_promoter' => $sliderCode,
				]);
			}

			$metrika->setStatus(Metrika\Statuses::ErrorTurnedOff)->send();

			\localRedirect($url->getUri());
		}
		$metrika->send();

		$this->arResult['INITIAL_PROMPT'] = $this->getInitialPrompt();
		$this->arResult['HAS_INITIAL_PROMPT'] = $this->arResult['INITIAL_PROMPT'] !== '';
		$this->prepareAiSiteTriggerData();

		$this->arResult += (new WidgetDataProvider())->getData();

		parent::executeComponent();
	}

	private function prepareAiSiteTriggerData(): void
	{
		$userId = $this->getCurrentUserId();
		$bindingId = $userId > 0
			? $this->getAiSiteBindingService()->getOrCreateFreeDraft($userId)
			: 0
		;
		$triggerAvailabilityErrorCode = $this->getAiSiteTriggerAvailabilityErrorCode($bindingId);

		$this->arResult['AI_SITE_BINDING_ID'] = $bindingId;
		$this->arResult['AI_SITE_TRIGGER_CODE'] = AiSiteChatBindingContract::TRIGGER_CODE;
		$this->arResult['AI_SITE_TRIGGER_CONTEXT'] = $this->getAiSiteTriggerContext($bindingId);
		$this->arResult['AI_SITE_TRIGGER_ENABLED'] = $bindingId > 0 && $triggerAvailabilityErrorCode === '';
		$this->arResult['AI_SITE_TRIGGER_ERROR_CODE'] = $bindingId > 0
			? $triggerAvailabilityErrorCode
			: self::AI_SITE_TRIGGER_ERROR_ENTITY_REQUIRED
		;
	}

	private function getAiSiteTriggerContext(int $bindingId): array
	{
		$initialMessage = $this->hasInitialPrompt()
			? ''
			: $this->getAiSiteTriggerInitialMessage()
		;

		return $this->getAiSiteChatContextService()->getSitesAiContext(
			$bindingId,
			$this->getAiSiteTriggerSuggestedTitle(),
			$initialMessage,
		);
	}

	protected function getCurrentUserId(): int
	{
		return Manager::getUserId();
	}

	protected function getAiSiteBindingService(): AiSiteBindingService
	{
		return new AiSiteBindingService();
	}

	protected function getAiSiteChatContextService(): AiSiteChatContextService
	{
		return new AiSiteChatContextService();
	}

	protected function getAiSiteChatAvailabilityService(): AiSiteChatAvailabilityService
	{
		return new AiSiteChatAvailabilityService();
	}

	protected function getAiSiteTriggerAvailabilityErrorCodeOverride(): ?string
	{
		return null;
	}

	private function hasInitialPrompt(): bool
	{
		return ($this->arResult['HAS_INITIAL_PROMPT'] ?? false) === true;
	}

	private function getAiSiteTriggerSuggestedTitle(): string
	{
		return $this->getAiSiteTriggerMessage(self::AI_SITE_TRIGGER_TITLE_MESSAGE, 'AI site');
	}

	private function getAiSiteTriggerInitialMessage(): string
	{
		return $this->getAiSiteTriggerMessage(self::AI_SITE_TRIGGER_INITIAL_MESSAGE, '');
	}

	private function getAiSiteTriggerMessage(string $messageCode, string $fallback): string
	{
		$message = Loc::getMessage($messageCode);
		if (!is_string($message) || $message === '')
		{
			$message = $fallback;
		}

		return NameService::replaceCopilotName($message);
	}

	private function getAiSiteTriggerAvailabilityErrorCode(int $bindingId): string
	{
		$override = $this->getAiSiteTriggerAvailabilityErrorCodeOverride();
		if ($override !== null)
		{
			return $override;
		}

		return $this->getAiSiteChatAvailabilityService()
			->getTriggerUnavailableReason($bindingId)
			?? ''
		;
	}

	private function getInitialPrompt(): string
	{
		if (!$this->isAgreementAccepted())
		{
			return '';
		}

		if ($this->currentRequest->isPost() && check_bitrix_sessid())
		{
			return $this->normalizeInitialPrompt($this->request(self::INITIAL_PROMPT_FIELD));
		}

		return $this->consumeInitialPromptFromSession();
	}

	private function isAgreementAccepted(): bool
	{
		$agreementAccepted = $this->arParams['AGREEMENT_ACCEPTED'] ?? true;

		return $agreementAccepted === true || $agreementAccepted === 'Y';
	}

	private function consumeInitialPromptFromSession(): string
	{
		$session = Application::getInstance()->getSession();
		if (!$session->has(self::INITIAL_PROMPT_SESSION_KEY))
		{
			return '';
		}

		$initialPromptData = $session->get(self::INITIAL_PROMPT_SESSION_KEY);
		$session->remove(self::INITIAL_PROMPT_SESSION_KEY);
		if (!is_array($initialPromptData))
		{
			return '';
		}

		$token = (string)$this->request(self::INITIAL_PROMPT_TOKEN_FIELD);
		$storedToken = (string)($initialPromptData['token'] ?? '');
		$createdAt = (int)($initialPromptData['createdAt'] ?? 0);
		if (
			$token === ''
			|| $storedToken === ''
			|| $token !== $storedToken
			|| $createdAt <= 0
			|| time() - $createdAt > self::INITIAL_PROMPT_SESSION_TTL
		)
		{
			return '';
		}

		$prompt = $this->normalizeInitialPrompt($initialPromptData['prompt'] ?? '');

		return $prompt;
	}

	private function normalizeInitialPrompt($prompt): string
	{
		if (!is_scalar($prompt))
		{
			return '';
		}

		$prompt = str_replace(["\r\n", "\r"], "\n", (string)$prompt);
		$prompt = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $prompt);
		if (!is_string($prompt))
		{
			return '';
		}

		$prompt = trim($prompt);
		if (mb_strlen($prompt) > self::INITIAL_PROMPT_MAX_LENGTH)
		{
			$prompt = mb_substr($prompt, 0, self::INITIAL_PROMPT_MAX_LENGTH);
		}

		return $prompt;
	}
}
