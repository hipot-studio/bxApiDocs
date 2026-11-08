<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Documentation\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\ChangeDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\CursorExpiredException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\CursorInvalidException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\FeatureNotSupportedException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SyncAccessDeniedException;
use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\StrictRequest;
use Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync\SectionsStructure;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Schema\TypeAliasRegistry;

/**
 * Documentation of disk.sync.changes.list.
 */
final class ChangesListMethodProvider extends SyncMethodProvider
{
	private const PARAMETER_CURSOR = 'cursor';
	private const PARAMETER_LIMIT = 'limit';
	private const PARAMETER_SECTIONS = 'sections';

	protected function getItemDto(): Dto
	{
		return ChangeDto::create();
	}

	/**
	 * One refusal of the validation layer and no more: every one of them is published under the same
	 * code, so a second example would only repeat that code in the schema while replacing the example
	 * of the first. The one kept is the refusal about the size of a page; the missing cursor and the
	 * unavailable section are named in the description of the parameters they are about.
	 */
	protected function getRefusals(): array
	{
		return [
			new FeatureNotSupportedException(),
			new SyncAccessDeniedException(),
			new CursorExpiredException(),
			new CursorInvalidException(),
			StrictRequest::refusal(
				self::PARAMETER_LIMIT,
				'DISK_SYNC_REQUEST_LIMIT_TOO_LARGE',
				['#MAX#' => (string)(new SyncLimits())->getPageSizeMax()],
			),
		];
	}

	/**
	 * The page, and not the single-item envelope of the rest of the contour: the position of the next
	 * page and its expiry travel beside the records.
	 */
	protected function describeSuccess(): array
	{
		return [
			'description' => 'One page of the journal of changes.',
			'content' => [
				'application/json' => [
					'schema' => [
						'type' => 'object',
						'properties' => [
							'result' => [
								'type' => 'object',
								'properties' => [
									'items' => [
										'type' => 'array',
										'description' => 'The records of the page, in the order they were published.',
										'items' => [
											'$ref' => '#/components/schemas/'
												. TypeAliasRegistry::toPublicType($this->getItemDto())
											,
										],
									],
									'hasMore' => [
										'type' => 'boolean',
										'description' => 'Whether the journal goes on above this page.',
									],
									'nextCursor' => [
										'type' => 'string',
										'description' =>
											'The position the next page is asked for by. Answered on the '
											. 'last page as well.'
										,
									],
									'cursorExpiresAt' => [
										'type' => 'string',
										'format' => 'date-time',
										'description' =>
											'When the position stops being accepted. Stamped when the '
											. 'session was created and not moved by reading: every page '
											. 'of that session answers the same moment.'
										,
									],
									'warnings' => [
										'type' => 'array',
										'description' =>
											'What this page could not describe. Empty in the usual case.'
										,
										'items' => [
											'type' => 'object',
											'properties' => [
												'resourceType' => ['type' => 'string'],
												'resourceId' => ['type' => 'integer'],
												'reason' => ['type' => 'string'],
											],
										],
									],
								],
							],
						],
					],
				],
			],
		];
	}

	protected function getRequestBody(): array
	{
		$limits = new SyncLimits();

		return [
			'content' => [
				'application/json' => [
					'schema' => [
						'type' => 'object',
						'required' => [self::PARAMETER_CURSOR],
						'properties' => [
							self::PARAMETER_CURSOR => [
								'type' => 'string',
								'description' =>
									'Where the reading of the journal stands. Opaque: it comes from '
									. 'disk.sync.session.create and then from every page, and is given back '
									. 'exactly as it was received.'
								,
							],
							self::PARAMETER_LIMIT => [
								'type' => 'integer',
								'minimum' => 1,
								'maximum' => $limits->getPageSizeMax(),
								'description' =>
									'How many records of the page are asked for, ' . $limits->getPageSizeMax()
									. ' by default. A value above the ceiling is refused rather than trimmed. '
									. 'A page that asks for a heavy section is served at most '
									. $limits->getPageSizeMaxHeavy() . ' records - a shorter page, not a '
									. 'refusal. Both ceilings are published by disk.sync.capabilities.get.'
								,
							],
							self::PARAMETER_SECTIONS => [
								'type' => 'array',
								'items' => ['type' => 'string', 'enum' => SectionsStructure::getDeclaredSections()],
								'description' =>
									'The sections of a card to fill on the records that carry one. '
									. 'Everything this portal serves when the parameter is absent. A section '
									. 'the portal declares but does not fill is refused when asked for rather '
									. 'than answered empty, and so is a parameter the schema does not declare '
									. 'at all.'
								,
							],
						],
					],
				],
			],
		];
	}

	protected function getTitle(): ?string
	{
		return 'One page of the journal of changes';
	}

	protected function getDescription(): ?string
	{
		return <<<'TEXT'
			Hands over what has happened to the files of the caller since the position they name, in the
			order the portal published it.

			Ask for the next page with the `nextCursor` of the previous one and stop when `hasMore` says
			the journal is over. A page that came back short is not the end of it: records fall out to
			the confirmation of visibility, and only `hasMore` answers that question. `hasMore` is
			decided against the tail the portal considers settled, so the youngest records of the
			journal - the ones that may still be on their way in - are held back rather than handed over
			and forgotten. That is what disk.sync.capabilities.get publishes as safeWindowSeconds, and
			it is applied by the server: a client neither names that boundary nor has to compensate for
			it.

			Applying the same page twice is safe. The cursor moves only over records the page actually
			walked, so a client that failed before it saved the cursor asks with the old one and is
			handed the same records again - and applying a change of an object, a tombstone or a change
			of access a second time leaves the memory of the client exactly as the first time did.

			A record about an object carries the card of that object as it stood when the record was
			published. A tombstone and a change of access carry no card at all, which is what lets them
			reach a client that can no longer see the object: a tombstone says what the client holds is
			to be dropped, a change of access says a boundary of visibility moved and names the scope to
			look at again.

			`cursor` belongs to the pair of a user and an application, and not to the user alone. A
			cursor handed to another application of the same user is refused with CURSOR_INVALID, the
			same code as a cursor of another user or a damaged one. The cursor outlives the session it
			came with: opening a session again does not annul it, and a client that has just replaced
			its session goes on reading the journal from where it stood.

			`cursorExpiresAt` is when the position stops being accepted. It is stamped when the session
			was created and is not moved by reading: the position handed out by the newest page carries
			the same moment as the one the session began with. A session is therefore read for the
			retention of the journal counted from its creation - disk.sync.capabilities.get publishes
			that as journalRetentionDays - however fresh the position of the client is, and after that
			every position of that session is refused with CURSOR_EXPIRED.

			A client that means to go on reading creates a session again before that moment. The cheap
			way is to do it while caught up: read until `hasMore` says the journal is over, create the
			session straight away and go on from the cursor it hands back. In that order nothing is
			lost and nothing has to be reconciled - the cursor of a new session is stamped a safe
			window back from the moment it was created, so a client that has just reached the end of
			the journal is handed a position at or behind its own, and the records it is handed twice
			change nothing. The fetch token of that session may simply be left unused: this is not a
			reason to run the initial fetch again.

			Rebuilding the memory is the answer to a position that was already refused, and only to
			that. A client that let its cursor expire cannot learn what happened while it was away -
			the journal no longer holds those records - so it reads the initial fetch of a new session
			and asks disk.sync.state.check about everything it still holds that the fetch did not name.

			`warnings` names what the page could not describe, and `reason` says which of the two it
			was. OBJECT_UNAVAILABLE is an object that is still visible to the caller and has no card to
			build - it was deleted between the publication of the record and the assembly of the page.
			RECORD_UNREADABLE is a record whose own shape is outside this contract: a kind of resource,
			a kind of change or a reason for a tombstone the server does not know, which is what a
			portal being deployed node by node produces while the rollout lasts. In both cases the page
			is formed round such a record and the position moves past it, so one unreadable object
			cannot stop the journal of a client for good. The identifier is part of the warning, because
			that is what lets a client read the object back - by disk.sync.state.check, which is the
			only way a warned record is ever recovered: the journal does not offer it again.
			TEXT;
	}
}
