<?php

declare(strict_types=1);

namespace Bitrix\Crm\WebForm\Telemetry;

use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\Signer;

final class SourceContextSigner
{
	public const PARAMETER = 'telemetry_sign';

	private const PURPOSE = 'crm.webform.telemetry.source.v1';
	private const VERSION = 'v1';
	private const DELIMITER = ':';

	public function sign(int $formId, string $source): string
	{
		if ($formId <= 0 || !in_array($source, SubmissionClassifier::SOURCE_CODES, true))
		{
			return '';
		}

		return (new Signer())->sign(
			implode(self::DELIMITER, [self::VERSION, (string)$formId, $source]),
			self::PURPOSE,
		);
	}

	public function verify(string $signedContext, int $formId): ?string
	{
		if ($signedContext === '' || $formId <= 0)
		{
			return null;
		}

		try
		{
			$payload = (new Signer())->unsign($signedContext, self::PURPOSE);
		}
		catch (BadSignatureException)
		{
			return null;
		}

		[$version, $signedFormId, $source] = array_pad(explode(self::DELIMITER, $payload, 3), 3, null);
		if ($version !== self::VERSION || (int)$signedFormId !== $formId)
		{
			return null;
		}

		return is_string($source) && in_array($source, SubmissionClassifier::SOURCE_CODES, true)
			? $source
			: null
		;
	}
}
