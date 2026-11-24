<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\RestException;

/**
 * The refusals of a publication a caller has to tell apart.
 *
 * Codes of their own, and that is the whole reason this class exists. saveTemplate reports every failure
 * with the code 0 - ACCESS_DENIED, WRONG_TEMPLATE, TEMPLATE_NOT_FOUND and the general catch of a graph the
 * engine rejects arrive indistinguishable - while what the caller does next differs per outcome: fix the
 * graph, re-read the draft, ask a human about the pilot, or stop trying. An outcome it has to branch on
 * reaches it as a code, never as a sentence to parse.
 *
 * The code is answered through getRegistryCode(). The default of RestException derives it from the name of
 * the class, so telling four outcomes apart that way would take four classes; overriding it is the device
 * the refusals of \Bitrix\Note and \Bitrix\Call whose code is part of a contract already use. The message
 * stays where the messages of this surface live: one phrase per outcome in the lang file of this class.
 *
 * Which of the outcomes reaches the exception log is a split, not a blanket. A refusal naming a state the
 * caller can see - no draft, a draft that changed, a live pilot - is the answer to what was asked and is
 * raised as {@see ClientPublishRefusedException}, which carries the skip marker. That split is not a
 * nicety: "no draft" is the ORDINARY answer to a publication whose response was lost, so an agent doing
 * the right thing would otherwise write a stack trace into the log of the portal on every retry.
 *
 * What stays here and reaches the log: a draft missing a field whose absence would erase the live one, and
 * a write the engine refused. Neither was asked for, both are worth a trace - the same line
 * {@see DraftSaveFailedException} draws on the write of a draft.
 */
class PublishRefusedException extends RestException
{
	use RussianMessageFallback;

	/** The template is in place and has nothing to publish. Deliberately not the answer of a missing template. */
	public const CODE_NO_DRAFT = 'BIZPROCDESIGNER_PUBLISH_NO_DRAFT';

	/** The blob of the draft changed between the read that produced the fingerprint and this call. */
	public const CODE_DRAFT_CHANGED = 'BIZPROCDESIGNER_PUBLISH_DRAFT_CHANGED';

	/** A pilot publication is live, and stopping one takes a confirmation this transport does not carry. */
	public const CODE_PILOT_ACTIVE = 'BIZPROCDESIGNER_PUBLISH_PILOT_ACTIVE';

	/** The write did not go through: the graph, the fields of the draft, or the portal itself. */
	public const CODE_REFUSED = 'BIZPROCDESIGNER_PUBLISH_REFUSED';

	protected function __construct(
		private readonly string $refusalCode,
		private readonly string $phraseCode,
		private readonly ?array $phraseReplacement = null,
	) {
		parent::__construct();
	}

	/**
	 * Not "template not found": the template is in place, there is simply nothing to publish. A caller told
	 * "not found" would go looking for a template it never lost. The id travels in the message because a
	 * caller may hold several, and this says which of them is empty.
	 */
	public static function noDraft(int $templateId): self
	{
		return new ClientPublishRefusedException(
			self::CODE_NO_DRAFT,
			'BIZPROCDESIGNER_REST_EXCEPTION_PUBLISH_NO_DRAFT',
			['#ID#' => (string)$templateId],
		);
	}

	/**
	 * The content the caller confirmed is not the content about to be committed. The message says where to
	 * take the fresh value from, because a caller that only hears "changed" retries with the same one.
	 */
	public static function draftChanged(): self
	{
		return new ClientPublishRefusedException(
			self::CODE_DRAFT_CHANGED,
			'BIZPROCDESIGNER_REST_EXCEPTION_PUBLISH_DRAFT_CHANGED',
		);
	}

	/** Stopping a pilot is a decision of a human, taken in the editor; this transport carries no such consent. */
	public static function pilotActive(): self
	{
		return new ClientPublishRefusedException(
			self::CODE_PILOT_ACTIVE,
			'BIZPROCDESIGNER_REST_EXCEPTION_PUBLISH_PILOT_ACTIVE',
		);
	}

	/**
	 * A draft missing a field whose absence the write would commit as an erasure of the live one. Answered
	 * with the code of a refused write, because there is nothing else the caller can do about it either:
	 * the draft has to be written again.
	 */
	public static function incompleteDraft(string $field): self
	{
		return new self(
			self::CODE_REFUSED,
			'BIZPROCDESIGNER_REST_EXCEPTION_PUBLISH_INCOMPLETE_DRAFT',
			['#FIELD#' => $field],
		);
	}

	/**
	 * The reason of the domain reaches the caller as it is, unlike the wrapped write of a draft
	 * {@see DraftSaveFailedException}. What fails here is the graph of the caller judged by the rules of the
	 * engine, and the text of that verdict is the one thing naming which activity to fix; it is built of the
	 * same activity errors template.validate already answers with, and it names no internals of the portal.
	 *
	 * A failure the portal named with no message at all is answered as such rather than with a message
	 * trailing off after a colon.
	 */
	public static function writeRefused(string $reason): self
	{
		return $reason === ''
			? new self(self::CODE_REFUSED, 'BIZPROCDESIGNER_REST_EXCEPTION_PUBLISH_REFUSED_UNKNOWN')
			: new self(
				self::CODE_REFUSED,
				'BIZPROCDESIGNER_REST_EXCEPTION_PUBLISH_REFUSED',
				['#DETAIL#' => $reason],
			);
	}

	public function getRegistryCode(): string
	{
		return $this->refusalCode;
	}

	protected function getMessagePhraseCode(): string
	{
		return $this->phraseCode;
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return $this->phraseReplacement;
	}
}
