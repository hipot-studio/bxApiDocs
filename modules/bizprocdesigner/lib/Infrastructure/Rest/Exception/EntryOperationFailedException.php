<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Main\Result;
use Bitrix\Rest\V3\Exception\RestException;

/**
 * An operation of the entry that was authorized and still did not go through: the session was not opened,
 * or the empty template was not written.
 *
 * A class of its own rather than one of the refusals of the contour, because nothing about the REQUEST was
 * wrong — the caller may not act on this, and answering it as "not found" or "denied" would send them
 * looking for a template or for rights that are both in order. What went wrong is on the portal side, and
 * the answer says so.
 *
 * The reason travels as the code of its phrase, resolved in the language of the answer by
 * {@see RussianMessageFallback} — the text of the underlying domain error never reaches the caller: it
 * names internals of the token store the agent has no use for and no right to.
 */
final class EntryOperationFailedException extends RestException
{
	use RussianMessageFallback;

	private const REASON_NO_ENDPOINT = 'BIZPROCDESIGNER_REST_EXCEPTION_ENTRY_FAILED_NO_ENDPOINT';
	private const REASON_TEMPLATE_NOT_WRITTEN = 'BIZPROCDESIGNER_REST_EXCEPTION_ENTRY_FAILED_TEMPLATE_NOT_WRITTEN';
	private const REASON_TEMPLATE_UNREADABLE = 'BIZPROCDESIGNER_REST_EXCEPTION_ENTRY_FAILED_TEMPLATE_UNREADABLE';
	private const REASON_UNKNOWN = 'BIZPROCDESIGNER_REST_EXCEPTION_ENTRY_FAILED_UNKNOWN';

	private function __construct(
		private readonly string $reasonPhraseCode,
		private readonly ?array $reasonReplacement = null,
	) {
		parent::__construct();
	}

	/** The token store refused to hand over an endpoint for a template the caller is allowed to work on. */
	public static function ofSessionResult(Result $result): self
	{
		return new self($result->isSuccess() ? self::REASON_NO_ENDPOINT : self::REASON_UNKNOWN);
	}

	/** The template was authorized and still not written. */
	public static function ofTemplateCreation(): self
	{
		return new self(self::REASON_TEMPLATE_NOT_WRITTEN);
	}

	/**
	 * The template WAS written and the contour then failed to read it back.
	 *
	 * Deliberately not EntityNotFoundException, though that is what the read said: the template exists, and
	 * a caller told "not found" would create a second one. The id travels in the message for the same
	 * reason - it is the only handle the caller has on a row that is already there.
	 */
	public static function ofTemplateReadBack(int $templateId): self
	{
		return new self(self::REASON_TEMPLATE_UNREADABLE, ['#ID#' => (string)$templateId]);
	}

	protected function getMessagePhraseCode(): string
	{
		return 'BIZPROCDESIGNER_REST_EXCEPTION_ENTRY_FAILED';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return ['#DETAIL#' => $this->messagePhrase($this->reasonPhraseCode, $this->reasonReplacement)];
	}
}
