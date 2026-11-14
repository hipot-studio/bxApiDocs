<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Copilot\Event;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Copilot\RegenerateDecision;
use Bitrix\Im\V2\Chat\Copilot\RegenerateErrorReason;
use Bitrix\Im\V2\Common\Event\BaseEvent;
use Bitrix\Im\V2\Message;
use Bitrix\Main\EventResult;

/**
 * Asks the aiassistant subscriber to repeat an answer and reads back its verdict. The event
 * type and parameters are unchanged, so the subscriber registered against the raw event keeps
 * receiving the call; returning a decision is optional and its absence means "no decision".
 */
class RegenerateAnswerEvent extends BaseEvent
{
	public const TYPE = 'OnCopilotMessageRegenerate';

	// A notification balloon is a narrow strip: a longer phrase breaks it, and a phrase that long
	// is a report about an error rather than something the user was meant to read.
	private const MAX_ERROR_TEXT_LENGTH = 200;

	/** @var array{decision: RegenerateDecision, reason: ?string, errorText: ?string}|false|null */
	private array|false|null $decided = null;

	public function __construct(Chat $chat, Message $message, Message $triggerMessage, int $userId)
	{
		parent::__construct(self::TYPE, [
			'chat' => $chat,
			'triggerMessage' => $triggerMessage,
			'message' => $message,
			'userId' => $userId,
		]);
	}

	public function getDecision(): ?RegenerateDecision
	{
		return $this->readDecision()['decision'] ?? null;
	}

	public function getReason(): ?string
	{
		return $this->readDecision()['reason'] ?? null;
	}

	/**
	 * Phrase to show the user, owned and already translated by the subscriber. im shows it and does
	 * not localize it here, the same way it publishes the stop notice of the assistant.
	 */
	public function getErrorText(): ?string
	{
		return $this->readDecision()['errorText'] ?? null;
	}

	/**
	 * The first SUCCESS result carrying a known decision wins; an attempt has a single verdict,
	 * so the rest are discarded instead of merged.
	 *
	 * @return array{decision: RegenerateDecision, reason: ?string, errorText: ?string}|null
	 */
	private function readDecision(): ?array
	{
		if ($this->decided !== null)
		{
			return $this->decided ?: null;
		}

		$this->decided = false;

		foreach ($this->getResults() as $result)
		{
			if ($result->getType() !== EventResult::SUCCESS)
			{
				continue;
			}

			$parameters = $result->getParameters();
			if (!is_array($parameters))
			{
				continue;
			}

			$decision = $this->toDecision($parameters['decision'] ?? null);
			if ($decision === null)
			{
				continue;
			}

			$this->decided = [
				'decision' => $decision,
				'reason' => $this->toReason($decision, $parameters),
				'errorText' => $this->toErrorText($decision, $parameters),
			];

			return $this->decided;
		}

		return null;
	}

	private function toDecision(mixed $value): ?RegenerateDecision
	{
		if ($value instanceof RegenerateDecision)
		{
			return $value;
		}

		// Untrusted subscriber data: an object or an array is skipped, never cast to string.
		if (!is_string($value))
		{
			return null;
		}

		return RegenerateDecision::tryFrom($value);
	}

	/**
	 * @param array<mixed> $parameters
	 */
	private function toReason(RegenerateDecision $decision, array $parameters): ?string
	{
		if ($decision !== RegenerateDecision::Error)
		{
			return null;
		}

		$reason = $parameters['reason'] ?? null;
		if (!is_string($reason))
		{
			return null;
		}

		// the set of causes is closed: an unrecognized one drives no decision on the client
		return RegenerateErrorReason::tryFrom($reason)?->value;
	}

	/**
	 * @param array<mixed> $parameters
	 */
	private function toErrorText(RegenerateDecision $decision, array $parameters): ?string
	{
		if ($decision !== RegenerateDecision::Error)
		{
			return null;
		}

		$errorText = $parameters['errorText'] ?? null;
		if (!is_string($errorText))
		{
			return null;
		}

		$errorText = trim($errorText);
		if ($errorText === '' || mb_strlen($errorText) > self::MAX_ERROR_TEXT_LENGTH)
		{
			return null;
		}

		return $errorText;
	}
}
