<?php

namespace Bitrix\Mobile\Internal\Onboarding\Providers;

use Bitrix\Mobile\Internal\Onboarding\Config\CampaignConfigProvider;
use Bitrix\Mobile\Internal\Onboarding\Config\StepConfig;
use Bitrix\Mobile\Internal\Onboarding\Dto\PushContent;
use Bitrix\Mobile\Internal\Onboarding\Industry;
use Bitrix\Mobile\Internal\Onboarding\PushType;

/**
 * Resolves the text of an activation-series push from the campaign document by the
 * key content[pushType][industry][attempt], falling back to the `_default` industry
 * bucket and to the `*` attempt key. Texts are authored data living in the
 * document; the bundled code default is only the fallback source.
 *
 * The attempt number is clamped to the maxAttempts of the step targeting the push
 * type, so an attempt beyond the authored variants still resolves.
 */
class PushContentProvider
{
	private const ANY_ATTEMPT = '*';

	public function __construct(private readonly CampaignConfigProvider $configProvider)
	{}

	public function getContent(PushType $type, int $attempt, Industry $industry): ?PushContent
	{
		$token = $type->getConfigToken();
		if ($token === null)
		{
			return null;
		}

		return $this->buildContent(
			$this->configProvider->getContent($token, $industry->value, $this->resolveAttemptKey($type, $attempt)),
			$type,
		);
	}

	/**
	 * Text of a final push: the same industry resolution as the series, but read from
	 * the `finals` section, which authors one variant per industry.
	 */
	public function getFinalContent(PushType $type, Industry $industry): ?PushContent
	{
		$finalKey = $type->getFinalKey();
		if ($finalKey === null)
		{
			return null;
		}

		return $this->buildContent($this->configProvider->getFinalContent($finalKey, $industry->value), $type);
	}

	/**
	 * The content leaf as the pipeline sees it. A leaf missing either half is no content at
	 * all: a push with an empty title or body is not sent, and the same rule stands in the
	 * acceptance of the document, which is what keeps such a leaf out of it.
	 *
	 * @param array{title: string, body: string}|null $leaf
	 */
	private function buildContent(?array $leaf, PushType $type): ?PushContent
	{
		if ($leaf === null || $leaf['title'] === '' || $leaf['body'] === '')
		{
			return null;
		}

		return new PushContent(
			title: $leaf['title'],
			text: $leaf['body'],
			type: $type,
		);
	}

	/**
	 * The attempt limit comes from the step VO, which carries the single default of the
	 * pipeline - the same bound the send decision applies, so no attempt key beyond the
	 * one that can actually be sent is ever asked for.
	 */
	private function resolveAttemptKey(PushType $type, int $attempt): string
	{
		if ($attempt < 1)
		{
			return self::ANY_ATTEMPT;
		}

		$maxAttempts = $this->configProvider->findStepByPushType($type)?->maxAttempts
			?? StepConfig::DEFAULT_MAX_ATTEMPTS
		;

		return (string)min($attempt, $maxAttempts);
	}
}
