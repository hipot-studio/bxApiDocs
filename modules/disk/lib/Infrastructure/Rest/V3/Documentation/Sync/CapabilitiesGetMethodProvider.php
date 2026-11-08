<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Documentation\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\CapabilitiesDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\FeatureNotSupportedException;
use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\StrictRequest;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Documentation of disk.sync.capabilities.get.
 */
final class CapabilitiesGetMethodProvider extends SyncMethodProvider
{
	private const UNSUPPORTED_PARAMETER_EXAMPLE = 'includeDeleted';

	protected function getItemDto(): Dto
	{
		return CapabilitiesDto::create();
	}

	protected function getRefusals(): array
	{
		return [
			new FeatureNotSupportedException(),
			StrictRequest::refusal(
				self::UNSUPPORTED_PARAMETER_EXAMPLE,
				'DISK_SYNC_REQUEST_UNKNOWN_PARAMETER',
				['#PARAMETER#' => self::UNSUPPORTED_PARAMETER_EXAMPLE],
			),
		];
	}

	/**
	 * The method takes no parameters. The empty property list is not decoration: it is what the strict
	 * request of the contour holds itself to, and what makes any parameter a client sends a refusal.
	 */
	protected function getRequestBody(): array
	{
		return [
			'content' => [
				'application/json' => [
					'schema' => [
						'type' => 'object',
						'properties' => [],
					],
				],
			],
		];
	}

	protected function getTitle(): ?string
	{
		return 'What this portal can synchronise';
	}

	protected function getDescription(): ?string
	{
		return <<<'TEXT'
			The first call a client makes, and the one every later call is planned against.

			Nothing here depends on who is asking: the answer describes the portal, not the caller, and
			the method reads nothing the caller owns. What it publishes is the schema version, the
			vocabulary of entities and of changes, the page ceilings, how long the journal of changes
			keeps a record, how long an interaction keeps an object in sight, and the three polling
			steps - the shortest one allowed, the recommended one and the longest one still accepted.

			`interactionWindowDays` is the one number that is about the memory of the portal rather than
			about the journal. An object a user only ever touched - opened through a link, or reached
			through an attachment of another module - is theirs to see while the window lasts, and is
			announced as a tombstone once it runs out. Nothing happened to such an object; the portal
			stopped counting the interaction as a reason to show it.

			The numbers are not constants of the product. A portal may shorten the life of its journal,
			and the polling bounds move with it, so a client that stores them once and never asks again
			will eventually plan against bounds the portal no longer honours. Ask on every start of a
			synchronisation cycle.

			`cardSections` names every section the schema declares and says, for each, whether this
			portal fills it. A section published as `false` is refused when it is asked for rather than
			answered empty: an empty section would be indistinguishable from "nothing is there".
			TEXT;
	}
}
