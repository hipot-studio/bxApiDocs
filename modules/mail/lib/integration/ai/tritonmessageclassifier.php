<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AI;

use Bitrix\AI\Context;
use Bitrix\AI\Engine;
use Bitrix\AI\Payload;
use Bitrix\Mail\Internal\Service\Message\ClassificationLabel;
use Bitrix\Mail\Internal\Service\Message\ClassificationOutcome;
use Bitrix\Mail\Internal\Service\Message\MessageClassifierInterface;
use Bitrix\Main\Error;

/**
 * Gateway to the classify engine of ai.
 */
class TritonMessageClassifier implements MessageClassifierInterface
{
	private const MODULE_ID = 'mail';

	public const PARAM_MAILBOX_ID = 'mail_mailbox_id';
	public const PARAM_MESSAGE_ID = 'mail_message_id';
	public const CONTEXT_ID = 'mail_auto_classify';

	private const TRITON_URGENT = 'urgent';
	private const TRITON_RISKY = 'risky';
	private const TRITON_LOST = 'lost';

	/** Refusals of the portal itself: the same letter sent again would be refused again. */
	private const POLICY_REFUSAL_CODES = [
		'MUST_AGREE_WITH_AGREEMENT',
		'SERVICE_IS_NOT_AVAILABLE_BY_TARIFF',
		'CURRENT_PROVIDER_IS_EXPIRED',
		'LIMIT_IS_EXCEEDED',
	];

	public function __construct(private readonly string $contextId)
	{
	}

	public function scheduleClassification(int $mailboxId, int $messageId, string $text): ClassificationOutcome
	{
		try
		{
			return $this->callEngine($mailboxId, $messageId, $text);
		}
		catch (\Throwable)
		{
			return ClassificationOutcome::TemporaryFailure;
		}
	}

	/**
	 * Static so the event seam can map an answer without holding a gateway.
	 *
	 * @return array<string, float>
	 */
	public static function extractLabels(?array $rawData): array
	{
		if ($rawData === null)
		{
			return [];
		}

		$probs = $rawData['probs'] ?? null;
		if (!is_array($probs))
		{
			return [];
		}

		$result = [];
		foreach ($probs as $key => $value)
		{
			$label = self::toLabel((string)$key);
			if ($label === null)
			{
				continue;
			}

			// A broken answer must not label the letter: true and a non-empty array both cast to 1.0
			if (!is_numeric($value))
			{
				continue;
			}

			$result[$label->value] = (float)$value;
		}

		return $result;
	}

	/** Engine keys are lowercase; the label enum owns the public values, not this mapping. */
	private static function toLabel(string $tritonKey): ?ClassificationLabel
	{
		return match ($tritonKey)
		{
			self::TRITON_URGENT => ClassificationLabel::Urgent,
			self::TRITON_RISKY => ClassificationLabel::Risky,
			self::TRITON_LOST => ClassificationLabel::Lost,
			default => null,
		};
	}

	/**
	 * The context is impersonal on purpose: no user is passed, so the request spends the shared Copilot
	 * budget of the portal.
	 */
	protected function callEngine(int $mailboxId, int $messageId, string $text): ClassificationOutcome
	{
		$context = new Context(self::MODULE_ID, $this->contextId);
		$context->setParameters([
			self::PARAM_MAILBOX_ID => $mailboxId,
			self::PARAM_MESSAGE_ID => $messageId,
		]);

		$engine = $this->resolveEngine($context);
		if ($engine === null)
		{
			return ClassificationOutcome::ConfigurationRefusal;
		}

		$outcome = ClassificationOutcome::Scheduled;

		$engine
			->setPayload(new Payload\Classify($text))
			->setHistoryState(false)
			->setResponseJsonMode(true)
			// A refused request is reported through the callback while the call itself returns normally:
			// without it an exhausted ai quota looks exactly like a letter the model found no label for
			->onError(function (Error $error) use (&$outcome): void {
				$outcome = self::toOutcome((string)$error->getCode());
			})
			->completionsInQueue()
		;

		// The callback fires inside completionsInQueue, so the outcome is settled only once it returns
		return $outcome;
	}

	/**
	 * getByCategory bakes the context into the engine, so a resolved engine cannot be reused for the next
	 * letter.
	 */
	protected function resolveEngine(Context $context): ?Engine
	{
		return Engine::getByCategory(Engine::CATEGORIES['classify'], $context);
	}

	/**
	 * Refusal codes carry suffixes (LIMIT_IS_EXCEEDED_BAAS_RATE_LIMIT), hence the match by prefix.
	 * An unknown code is treated as temporary: the unknown is better repeated than silently lost.
	 */
	protected static function toOutcome(string $code): ClassificationOutcome
	{
		foreach (self::POLICY_REFUSAL_CODES as $prefix)
		{
			if (str_starts_with($code, $prefix))
			{
				return ClassificationOutcome::PolicyRefusal;
			}
		}

		return ClassificationOutcome::TemporaryFailure;
	}
}

