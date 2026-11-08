<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class AiAgentsScenarioLauncher
{
	public const REFUSAL_SECTION_CLOSED = 'section_closed';
	public const REFUSAL_CARD_NOT_FOUND = 'card_not_found';
	public const REFUSAL_TARIFF = 'tariff';
	public const REFUSAL_UNAVAILABLE = 'unavailable';
	public const REFUSAL_PREPARING = 'preparing';
	public const REFUSAL_BOT_NOT_REGISTERED = AiAgentsChatGate::BOT_NOT_REGISTERED;
	public const REFUSAL_CHAT_NOT_CREATED = AiAgentsChatGate::CHAT_NOT_CREATED;
	public const REFUSAL_MESSAGE_NOT_WRITTEN = AiAgentsChatGate::MESSAGE_NOT_WRITTEN;

	public const CHAT_ID_KEY = 'chatId';
	public const REFUSAL_KEY = 'refusal';
	public const CARD_ID_KEY = 'cardId';

	/** Refusal codes drive client states; messages are technical diagnostics. */
	private const REFUSALS = [
		self::REFUSAL_SECTION_CLOSED => [
			'code' => self::REFUSAL_UNAVAILABLE,
			'message' => 'AI agents section is closed.',
		],
		self::REFUSAL_CARD_NOT_FOUND => [
			'code' => self::REFUSAL_CARD_NOT_FOUND,
			'message' => 'Scenario card is not in the catalog.',
		],
		self::REFUSAL_TARIFF => [
			'code' => self::REFUSAL_TARIFF,
			'message' => 'Bitrix24 plan does not include the BI Builder.',
		],
		self::REFUSAL_UNAVAILABLE => [
			'code' => self::REFUSAL_UNAVAILABLE,
			'message' => 'BI Builder is not available.',
		],
		self::REFUSAL_PREPARING => [
			'code' => self::REFUSAL_PREPARING,
			'message' => 'BI Builder is still being deployed.',
		],
		self::REFUSAL_BOT_NOT_REGISTERED => [
			'code' => self::REFUSAL_UNAVAILABLE,
			'message' => 'Copilot chat bot is not registered.',
		],
		self::REFUSAL_CHAT_NOT_CREATED => [
			'code' => self::REFUSAL_CHAT_NOT_CREATED,
			'message' => 'Copilot chat was not created.',
		],
		self::REFUSAL_MESSAGE_NOT_WRITTEN => [
			'code' => self::REFUSAL_MESSAGE_NOT_WRITTEN,
			'message' => 'Opening message was not written.',
		],
	];

	private readonly AiAgentsChatGate $chatGate;

	public function __construct(?AiAgentsChatGate $chatGate = null)
	{
		$this->chatGate = $chatGate ?? new AiAgentsChatGate();
	}

	public static function getErrorCode(string $refusal): string
	{
		return self::REFUSALS[$refusal]['code'] ?? self::REFUSAL_UNAVAILABLE;
	}

	/**
	 * Each successful request creates a separate chat.
	 * @return Result Chat ID or refusal, plus the card ID when matched.
	 */
	public function launch(string $cardId): Result
	{
		if (!AiAgentsSection::isAvailable())
		{
			return $this->refuse(self::REFUSAL_SECTION_CLOSED);
		}

		$card = $this->findCard($cardId);
		if ($card === null)
		{
			return $this->refuse(self::REFUSAL_CARD_NOT_FOUND);
		}

		$availability = AiAgentsScenarioAvailability::check();
		if (!($availability['available'] ?? false))
		{
			return $this->refuse($this->resolveStateRefusal((string)($availability['state'] ?? '')), $card['id']);
		}

		$chatResult = $this->chatGate->launch((int)CurrentUser::get()->getId(), $card);
		if (!$chatResult->isSuccess())
		{
			return $this->refuse($this->resolveGateRefusal($chatResult), $card['id']);
		}

		return (new Result())->setData([
			self::CHAT_ID_KEY => (int)($chatResult->getData()[AiAgentsChatGate::CHAT_ID_KEY] ?? 0),
			self::CARD_ID_KEY => $card['id'],
		]);
	}

	/**
	 * @return array{id: string, group: string, title: string, description: string, prompt: string}|null
	 */
	private function findCard(string $cardId): ?array
	{
		foreach (AiAgentsScenarioCatalog::getCards() as $card)
		{
			if ($card['id'] === $cardId)
			{
				return $card;
			}
		}

		return null;
	}

	private function resolveStateRefusal(string $state): string
	{
		return match ($state)
		{
			AiAgentsScenarioAvailability::STATE_TARIFF => self::REFUSAL_TARIFF,
			AiAgentsScenarioAvailability::STATE_PREPARING => self::REFUSAL_PREPARING,
			default => self::REFUSAL_UNAVAILABLE,
		};
	}

	private function resolveGateRefusal(Result $chatResult): string
	{
		$error = $chatResult->getErrors()[0] ?? null;
		$refusal = $error === null ? '' : (string)$error->getCode();
		$gateRefusals = [
			self::REFUSAL_BOT_NOT_REGISTERED,
			self::REFUSAL_CHAT_NOT_CREATED,
			self::REFUSAL_MESSAGE_NOT_WRITTEN,
		];

		return in_array($refusal, $gateRefusals, true) ? $refusal : self::REFUSAL_CHAT_NOT_CREATED;
	}

	private function refuse(string $refusal, string $cardId = ''): Result
	{
		$row = self::REFUSALS[$refusal];

		return (new Result())
			->setData([self::REFUSAL_KEY => $refusal, self::CARD_ID_KEY => $cardId])
			->addError(new Error($row['message'], $row['code']))
		;
	}
}
