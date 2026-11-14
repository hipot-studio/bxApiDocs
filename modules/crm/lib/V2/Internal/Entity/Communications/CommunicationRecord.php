<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Communications;

/**
 * One conversation of the history of a CRM item: what kind it was, when it happened, who talked and
 * what the speech analysis had to say about it.
 *
 * The record carries no identifier of the activity, of the deal or of the client it belongs to: an
 * agent is told about the conversation and not about the rows behind it. The text of the conversation
 * is not carried either - the history answers by the summary the analysis wrote and never by the words
 * that were said.
 *
 * An empty duration, summary, theme or intent is a state of a conversation and not a failure - a call
 * entered by hand has no telephony statistics, a letter has no duration at all, and a conversation
 * nobody analyzed has no texts.
 *
 * `toArray()` is the wire form of the record: the keys are normative, so renaming one breaks whoever
 * reads them.
 *
 * @internal
 */
final class CommunicationRecord
{
	/**
	 * @param string $date Start of the conversation in the format of the site.
	 * @param string|null $manager Name of the employee who talked, null when the responsible one is
	 * not found.
	 * @param string|null $client Name of the person on the client side, null when the client is
	 * hidden from the user or the conversation carries no client at all.
	 * @param int|null $durationSec Seconds of a call of the telephony that has statistics; null for
	 * every other kind and for a call outside the telephony.
	 */
	public function __construct(
		public readonly CommunicationKind $kind,
		public readonly string $date,
		public readonly CommunicationDirection $direction,
		public readonly ?string $manager,
		public readonly ?string $client,
		public readonly bool $isClientHidden,
		public readonly ?int $durationSec,
		public readonly ?string $summary,
		public readonly ?string $theme,
		public readonly ?string $intent,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'kind' => $this->kind->value,
			'date' => $this->date,
			'direction' => $this->direction->value,
			'manager' => $this->manager,
			'client' => $this->client,
			'isClientHidden' => $this->isClientHidden,
			'durationSec' => $this->durationSec,
			'summary' => $this->summary,
			'theme' => $this->theme,
			'intent' => $this->intent,
		];
	}
}
