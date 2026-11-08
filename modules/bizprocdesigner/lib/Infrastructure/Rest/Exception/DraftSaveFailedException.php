<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Main\Result;
use Bitrix\Rest\V3\Exception\RestException;

/**
 * A graph that passed validation but was not written as a draft.
 *
 * The reason is carried into the message by a phrase of this surface, chosen by the code of the domain
 * error: the text of the domain error itself never reaches the public answer.
 *
 * This class stands for a write that failed on its own and reaches the exception log of the portal. An
 * expected refusal the caller can act on is answered with {@see ClientDraftSaveFailedException} - same
 * status, code and text, kept out of the log. The agent cannot tell the two apart, and is not meant to.
 */
class DraftSaveFailedException extends RestException
{
	use RussianMessageFallback;

	/**
	 * Expected refusal reasons the caller can act on. A code that is not here is treated as a failure of the
	 * write itself.
	 *
	 * @var array<string, string> domain error code => phrase of the reason answered for it
	 */
	private const CLIENT_REASON_PHRASES = [
		'ACCESS_DENIED' => 'BIZPROCDESIGNER_REST_EXCEPTION_DRAFT_SAVE_FAILED_ACCESS_DENIED',
		'TEMPLATE_NOT_FOUND' => 'BIZPROCDESIGNER_REST_EXCEPTION_DRAFT_SAVE_FAILED_TEMPLATE_NOT_FOUND',
		'TEMPLATE_DRAFT_CONFLICT' => 'BIZPROCDESIGNER_REST_EXCEPTION_DRAFT_SAVE_FAILED_TEMPLATE_DRAFT_CONFLICT',
	];

	private const REASON_NO_DRAFT_ID = 'BIZPROCDESIGNER_REST_EXCEPTION_DRAFT_SAVE_FAILED_NO_DRAFT_ID';
	private const REASON_UNKNOWN = 'BIZPROCDESIGNER_REST_EXCEPTION_DRAFT_SAVE_FAILED_UNKNOWN';

	protected function __construct(
		private readonly string $reasonPhraseCode,
	) {
		parent::__construct();
	}

	public static function ofSaveResult(Result $saveResult): self
	{
		// The write reported success and still answered with no draft id: nothing named a failure, and there
		// is no draft to read the template back through.
		if ($saveResult->isSuccess())
		{
			return new self(self::REASON_NO_DRAFT_ID);
		}

		$clientReason = self::clientReason($saveResult);

		return $clientReason === null
			? new self(self::REASON_UNKNOWN)
			: new ClientDraftSaveFailedException($clientReason);
	}

	private static function clientReason(Result $saveResult): ?string
	{
		foreach ($saveResult->getErrors() as $error)
		{
			$phraseCode = self::CLIENT_REASON_PHRASES[(string)$error->getCode()] ?? null;
			if ($phraseCode !== null)
			{
				return $phraseCode;
			}
		}

		return null;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'BIZPROCDESIGNER_REST_EXCEPTION_DRAFT_SAVE_FAILED';
	}

	/**
	 * The reason is stored as the code of its phrase and read here, where the language of the answer is
	 * already resolved: resolving it earlier would answer the wrapping phrase and the reason in two different
	 * languages once the translations of the surface arrive.
	 */
	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#DETAIL#' => $this->messagePhrase($this->reasonPhraseCode),
		];
	}
}
