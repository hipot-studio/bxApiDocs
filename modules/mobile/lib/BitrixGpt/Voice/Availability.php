<?php

declare(strict_types=1);

namespace Bitrix\Mobile\BitrixGpt\Voice;

use Bitrix\AI;
use Bitrix\AiAssistant\Service\MartaReadiness;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Public\Provider\Copilot\AnswerProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class Availability
{
	public function __construct(
		private readonly AudioEngine $audioEngine,
		private readonly ?MartaReadiness $martaReadiness,
	)
	{
	}

	public function isAvailable(int $userId): bool
	{
		return $this->getAudioEngine($userId) !== null;
	}

	public function checkRecording(int $userId): Result
	{
		$result = new Result();
		$engine = $this->getAudioEngine($userId);
		if ($engine === null)
		{
			return $result->addError(new Error('Voice widget is unavailable.', 'AI_UNAVAILABLE'));
		}

		$readiness = $this->martaReadiness?->check($userId);
		if ($readiness === null)
		{
			return $result->addError(new Error('Voice widget is unavailable.', 'AI_UNAVAILABLE'));
		}

		if (!$readiness->isSuccess())
		{
			return $readiness;
		}

		if (!$this->isExternalRequestAllowed() || !$engine->isAvailableByAgreement())
		{
			return $result->addError(new Error('AI agreement is required.', 'MUST_AGREE_WITH_AGREEMENT'));
		}

		if (!$engine->isAvailableByTariff())
		{
			return $result->addError(new Error('AI is unavailable on this plan.', 'SERVICE_IS_NOT_AVAILABLE_BY_TARIFF'));
		}

		if ($engine->isExpired())
		{
			return $result->addError(new Error('AI provider has expired.', 'CURRENT_PROVIDER_IS_EXPIRED'));
		}

		if ($engine->getIEngine()->checkLimits())
		{
			$reservation = $this->getLimitControlService()->reserveRequest(
				new AI\Limiter\Usage($engine->getIEngine()->getContext()),
			);
			if (!$reservation->isSuccess())
			{
				return $result->addError(new Error('AI request limit is exceeded.', 'LIMIT_IS_EXCEEDED'));
			}
		}

		return $result;
	}

	protected function getAudioEngine(int $userId): ?AI\Engine
	{
		if (
			$userId <= 0
			|| !$this->isVoiceWidgetActive()
			|| !$this->isChatAvailable($userId)
			|| !$this->audioEngine->isAvailable($userId)
		)
		{
			return null;
		}

		return $this->audioEngine->getEngine($userId);
	}

	protected function isVoiceWidgetActive(): bool
	{
		return Loader::includeModule('im') && Features::isBitrixGptVoiceWidgetActive();
	}

	protected function isChatAvailable(int $userId): bool
	{
		return $this->martaReadiness !== null
			&& Loader::includeModule('im')
			&& class_exists(AnswerProvider::class)
			&& CopilotChat::checkCopilotAvailability()->isSuccess()
			&& $this->martaReadiness->isAvailable($userId);
	}

	protected function isExternalRequestAllowed(): bool
	{
		return (new AI\Services\BitrixGptAgreementService())->isAvailableForExternalRequest();
	}

	protected function getLimitControlService(): AI\Limiter\LimitControlService
	{
		return new AI\Limiter\LimitControlService();
	}
}
