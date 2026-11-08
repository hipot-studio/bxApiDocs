<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Provider;

use Bitrix\BIConnector\Internal\Digest\DigestPeriod;
use Bitrix\Bizproc\Public\Service\AiAgent\SystemTemplateSetupService;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * Copy of the digest system template prepared for one user: the workflow module resolves the source in full,
 * copies it and fills the presets in.
 * Left open for extension so tests can replace it with a stub.
 */
class DigestSystemTemplatePreparer
{
	public const ERROR_UNAVAILABLE = 'BICONNECTOR_DIGEST_SETUP_UNAVAILABLE';

	private const ERROR_PRESET_INVALID = 'AI_AGENT_SETUP_PRESET_INVALID';

	public function prepare(string $systemCode, int $userId, array $presetValues): Result
	{
		if (!Loader::includeModule('bizproc'))
		{
			return (new Result())->addError(new Error('Digest setup is unavailable.', self::ERROR_UNAVAILABLE));
		}

		$service = new SystemTemplateSetupService();
		$result = $service->prepare($systemCode, $userId, $presetValues);
		$errors = $result->getErrors();
		if (
			count($errors) !== 1
			|| $errors[0]->getCode() !== self::ERROR_PRESET_INVALID
			|| !in_array($presetValues['period'] ?? null, [
				DigestPeriod::PreviousWeek->value,
				DigestPeriod::PreviousMonth->value,
				DigestPeriod::PreviousYear->value,
			], true)
		)
		{
			return $result;
		}

		// Older system templates support the current month but not previous calendar periods.
		$presetValues['period'] = DigestPeriod::CurrentMonth->value;

		return $service->prepare($systemCode, $userId, $presetValues);
	}
}
