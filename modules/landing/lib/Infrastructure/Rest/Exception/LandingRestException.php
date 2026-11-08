<?php

declare(strict_types=1);

namespace Bitrix\Landing\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\RestException;

/**
 * A refusal of the landing REST contract.
 *
 * An heir the caller causes declares SkipWriteToLogException itself, so that the refusal is
 * answered without a journal record. An heir that reports a state of the portal - a missing
 * module, for one - does not: nothing about the caller is wrong there, and the outage has to
 * stay visible in the log.
 *
 * An heir declares the STATUS it answers with and the registry code the client reads, and puts its
 * phrase into lang/ru/lib/Infrastructure/Rest/Exception/<Class>.php under the name built here: the
 * phrase is named after the class, so nothing else keeps the two in step.
 */
abstract class LandingRestException extends RestException
{
	protected function getMessagePhraseCode(): string
	{
		$className = (new \ReflectionClass(static::class))->getShortName();

		return 'LANDING_REST_EXCEPTION_' . strtoupper($className) . '_MSG';
	}
}
