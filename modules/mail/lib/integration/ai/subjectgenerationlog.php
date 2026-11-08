<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AI;

use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Psr\Log\LoggerInterface;

/**
 * Diagnostics of the subject generation. It lives next to the service rather than in a controller, so
 * every consumer of that service - the web compose form and the mobile application alike - leaves the
 * same trace.
 *
 * Records go to the default log of the portal rather than to a logger that has to be switched on
 * first: a feature spending the AI quota has to be diagnosable the moment it misbehaves.
 *
 * An exception thrown out of the service is not written here: both controllers hand it to the
 * exception log of the portal, which keeps the stack trace this class has no access to.
 */
class SubjectGenerationLog
{
	private const LOGGER_ID = 'mail.SubjectGeneration';

	/**
	 * The refusals the log is kept for: the service broke down and the portal has to be looked at.
	 *
	 * Every other refusal is either a defined answer of the API (an empty body, a switcher that is
	 * off, a spent quota, an agreement that is not accepted, an unknown composition mode) or a stable
	 * state of the portal the AI settings already tell apart (no text engine at all). Both are
	 * reproduced by every press of the button, and the default log of the portal is a FileLogger with
	 * maxSize 0, which is never rotated: writing a repeatable refusal grows the file without a bound.
	 */
	private const FAILURE_CODES = [
		SubjectGenerator::ERROR_PROMPT_NOT_FOUND,
		SubjectGenerator::ERROR_QUEUE_HASH_EMPTY,
	];

	private ?LoggerInterface $logger = null;

	/**
	 * The whole list of errors is written, not the failing ones alone: an accompanying refusal is a
	 * part of the picture the failure is diagnosed by.
	 *
	 * @param Error[] $errors
	 */
	public function logRefusal(array $errors): void
	{
		if (!$this->hasFailure($errors))
		{
			return;
		}

		/* The values are named in the message itself: the standard formatter substitutes context by
		{token} and drops whatever has no token, so a code left out of the text never reaches the file. */
		$this->getLogger()?->warning(
			'Subject generation refused by the service: {errors}',
			['errors' => $this->describeErrors($errors)],
		);
	}

	public function isFailure(string $code): bool
	{
		return in_array($code, self::FAILURE_CODES, true);
	}

	/**
	 * @param Error[] $errors
	 */
	private function hasFailure(array $errors): bool
	{
		foreach ($errors as $error)
		{
			if ($this->isFailure((string)$error->getCode()))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param Error[] $errors
	 * @return string Codes and messages of the errors, one entry per error.
	 */
	private function describeErrors(array $errors): string
	{
		return implode('; ', array_map(
			static fn(Error $error): string => (string)$error->getCode() . ': ' . $error->getMessage(),
			$errors,
		));
	}

	protected function getLogger(): ?LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory())->createById(
			self::LOGGER_ID,
			[],
			isCheckEnabledFromRegistry: false,
		);
	}
}
