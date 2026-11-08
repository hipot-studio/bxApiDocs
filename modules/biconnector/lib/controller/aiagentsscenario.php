<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Analytics\AnalyticsManager;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\AiAgentsScenarioAvailability;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\AiAgentsScenarioLauncher;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;

class AiAgentsScenario extends Controller
{
	private const LAUNCH_ACTION = 'launch';

	private string $refusal = '';

	private string $matchedCardId = '';

	/** Scope must precede method filtering; tariff refusals come from the scenario. */
	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new ActionFilter\Scope(ActionFilter\Scope::AJAX),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
		];
	}

	/**
	 * @return array{chatId: int}|null
	 */
	public function launchAction(string $cardId): ?array
	{
		$result = $this->createScenarioLauncher()->launch($cardId);
		$this->matchedCardId = (string)($result->getData()[AiAgentsScenarioLauncher::CARD_ID_KEY] ?? '');
		if (!$result->isSuccess())
		{
			$this->refusal = (string)($result->getData()[AiAgentsScenarioLauncher::REFUSAL_KEY] ?? '');
			$this->addErrors($result->getErrors());
			$this->addLimitCodeToTariffRefusal();

			return null;
		}

		$chatId = (int)($result->getData()[AiAgentsScenarioLauncher::CHAT_ID_KEY] ?? 0);
		if ($chatId <= 0)
		{
			$this->refusal = AiAgentsScenarioLauncher::REFUSAL_CHAT_NOT_CREATED;
			$this->addError(new Error(
				'Launch answered without a chat id.',
				AiAgentsScenarioLauncher::getErrorCode(AiAgentsScenarioLauncher::REFUSAL_CHAT_NOT_CREATED),
			));

			return null;
		}

		return [
			'chatId' => $chatId,
		];
	}

	protected function processAfterAction(Action $action, $result)
	{
		if (mb_strtolower($action->getName()) !== self::LAUNCH_ACTION)
		{
			return null;
		}

		// The hook also runs for REST requests rejected by the scope filter.
		if ($this->getScope() !== self::SCOPE_AJAX)
		{
			return null;
		}

		$isSuccess = $this->errorCollection->isEmpty();

		$this->sendAnalytics(
			$this->matchedCardId,
			$isSuccess ? '' : AnalyticsManager::resolveAiAgentsScenarioRefusalSlug($this->refusal),
			$isSuccess,
		);

		return null;
	}

	protected function sendAnalytics(string $cardId, string $slug, bool $isSuccess): void
	{
		AnalyticsManager::sendAiAgentsScenarioAnalytics($cardId, $slug, $isSuccess);
	}

	protected function createScenarioLauncher(): AiAgentsScenarioLauncher
	{
		return new AiAgentsScenarioLauncher();
	}

	private function addLimitCodeToTariffRefusal(): void
	{
		$tariffCode = AiAgentsScenarioLauncher::getErrorCode(AiAgentsScenarioLauncher::REFUSAL_TARIFF);

		// getValues() preserves the internal pointer used to read the first error.
		foreach ($this->errorCollection->getValues() as $offset => $error)
		{
			if ($error->getCode() !== $tariffCode)
			{
				continue;
			}

			$this->errorCollection->setError(
				new Error(
					$error->getMessage(),
					$error->getCode(),
					['limitCode' => AiAgentsScenarioAvailability::RESTRICTION_CODE_TARIFF],
				),
				$offset,
			);

			break;
		}
	}
}
