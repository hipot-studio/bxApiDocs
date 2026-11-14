<?php

namespace Bitrix\Call\DTO;

use Bitrix\Main\Type\ParameterDictionary;

/**
 * A restart of the follow-up analysis asked for from the admin panel of the media service
 * {@see \Bitrix\Call\Controller\CallController::restartFollowUpAction()}.
 *
 * The set of types to run is not named by `senses` alone: an empty list is completed by the other
 * marks of the request, and the table of that is written once, in the SDD of the feature (DTO-01).
 * `transcribe` may never be named in the list - it enters the set only through an empty list.
 *
 * @internal
 */
class FollowUpRestartRequest extends Hydrator
{
	/** Work from the copy of the recording the portal already holds. */
	public const SOURCE_STORED = 'stored';

	/** Take a new delivery of the recording that travels with the request. */
	public const SOURCE_DELIVERY = 'delivery';

	public string $callUuid = '';

	/**
	 * Obligatory, unlike the one of a file callback: a restart without a key would run without
	 * deduplication, and a repeated delivery of one request would spend the quota twice
	 * {@see \Bitrix\Call\Controller\Filter\UniqueRequestFilter}.
	 *
	 * Read as it arrives and not taken apart: the marks of the mode are already part of the id
	 * the sender derives from the operation, so the same id is the same ask
	 * {@see \Bitrix\CallController\Track\EventResender}.
	 */
	public string $requestId = '';

	/** @var string[] values of {@see \Bitrix\Call\Integration\AI\SenseType}, empty for "completed by the marks below" */
	public array $senses = [];

	/** Narrow the set to the types whose last task ended in a failure. */
	public bool $onlyFailed = false;

	/** A contentful result is no longer a reason not to run the type again. */
	public bool $force = false;

	/** Erase the previous results of the types being run (text types only). */
	public bool $purge = false;

	public string $source = self::SOURCE_STORED;

	/**
	 * The files of a new delivery; carried only by a request of the `delivery` source.
	 *
	 * A list and not one file: the follow-up event of the service joins the two files a call has
	 * of it - the pack the analysis reads and the audio record the chat shows - and mode 1 replaces
	 * both rows {@see \Bitrix\CallController\Track\EventResender::EVENT_TYPES}. Replacing one of
	 * them would close half of the reason for the mode: either the analysis runs on the new
	 * recording while the chat keeps the old one, or the other way round.
	 *
	 * An empty list of the `delivery` source is refused as a mode outside the contract
	 * {@see \Bitrix\Call\Controller\CallController::checkRestartMode()}.
	 *
	 * @var TrackFileRequest[]
	 */
	public array $tracks = [];

	/**
	 * The list as the validation, the selection of the set and the answer read it: trimmed,
	 * deduplicated and sorted, so the same request written in another order is the same request.
	 * Empty values are kept rather than dropped - an empty list means "completed by the other
	 * marks", and a list that holds an empty value is a list with an unknown type in it.
	 *
	 * @return string[]
	 */
	public function normalizedSenses(): array
	{
		$senses = array_map(
			static fn($sense): string => trim((string)$sense),
			$this->senses
		);
		$senses = array_values(array_unique($senses));
		sort($senses);

		return $senses;
	}

	/**
	 * The files of the delivery and the list of types are not scalars, which is all the base
	 * hydrator knows; everything else is left to it.
	 *
	 * A list that arrives as something other than a list becomes a one-value list on purpose: the
	 * action then refuses it as an unknown type, while dropping it would silently read a malformed
	 * request as "every type".
	 *
	 * The files are taken as they come and are not sorted or weeded out here: each of them is read
	 * exactly as one file of a delivery always was, and what a malformed one means is the answer of
	 * the action and not of the hydrator {@see \Bitrix\Call\Controller\CallController::checkRestartMode()}.
	 */
	protected function hydrate(\stdClass|ParameterDictionary $fields): void
	{
		$values = $fields instanceof ParameterDictionary ? $fields->toArray() : (array)$fields;

		$tracks = $values['tracks'] ?? null;
		unset($values['tracks']);
		if (is_array($tracks) || $tracks instanceof \stdClass)
		{
			$files = [];
			foreach ((array)$tracks as $track)
			{
				if (is_array($track) || $track instanceof \stdClass)
				{
					$files[] = new TrackFileRequest((array)$track);
				}
			}
			$this->tracks = $files;
		}

		if (array_key_exists('senses', $values))
		{
			$senses = $values['senses'];
			unset($values['senses']);

			$this->senses = match (true)
			{
				is_array($senses) => array_values($senses),
				$senses instanceof \stdClass => array_values((array)$senses),
				is_scalar($senses) => [(string)$senses],
				default => [],
			};
		}

		parent::hydrate((object)$values);
	}
}
