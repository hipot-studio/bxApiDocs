<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Documentation\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\CheckItemDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\CheckTokenExpiredException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\CursorInvalidException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\FeatureNotSupportedException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SyncAccessDeniedException;
use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\StrictRequest;
use Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync\SectionsStructure;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Schema\TypeAliasRegistry;

/**
 * Documentation of disk.sync.state.check.
 */
final class StateCheckMethodProvider extends SyncMethodProvider
{
	private const PARAMETER_OBJECT_IDS = 'objectIds';
	private const PARAMETER_SCOPE_ROOT_ID = 'scopeRootId';
	private const PARAMETER_CHECK_TOKEN = 'checkToken';
	private const PARAMETER_LIMIT = 'limit';
	private const PARAMETER_SECTIONS = 'sections';

	protected function getItemDto(): Dto
	{
		return CheckItemDto::create();
	}

	/**
	 * One refusal of the validation layer and no more: every one of them is published under the same
	 * code, so a second example would only repeat that code in the schema while replacing the example
	 * of the first. The one kept is the refusal this method has of its own - the two modes named at
	 * once - and the rest are named in the description of the parameters they are about.
	 */
	protected function getRefusals(): array
	{
		return [
			new FeatureNotSupportedException(),
			new SyncAccessDeniedException(),
			new CheckTokenExpiredException(),
			new CursorInvalidException(),
			StrictRequest::refusal(self::PARAMETER_OBJECT_IDS, 'DISK_SYNC_REQUEST_CHECK_MODE_AMBIGUOUS'),
		];
	}

	/**
	 * The verdicts, and - in the mode over an area alone - whether the area goes on and the position
	 * that continues it.
	 */
	protected function describeSuccess(): array
	{
		return [
			'description' => 'What is now true of the objects that were asked about.',
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
										'description' =>
											'One verdict per object. Over a list: the objects that were '
											. 'asked about, in the order they were asked about, once each. '
											. 'Over an area: the objects of the page, all of them visible.'
										,
										'items' => [
											'$ref' => '#/components/schemas/'
												. TypeAliasRegistry::toPublicType($this->getItemDto())
											,
										],
									],
									'hasMore' => [
										'type' => 'boolean',
										'description' =>
											'Whether the area goes on above this page. Absent from the answer '
											. 'over a list, which is the whole page there is.'
										,
									],
									'nextCheckToken' => [
										'type' => 'string',
										'description' =>
											'The position the next page of the area is asked for by, answered '
											. 'on the last page as well. Absent from the answer over a list.'
										,
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
						'properties' => [
							self::PARAMETER_OBJECT_IDS => [
								'type' => 'array',
								'items' => ['type' => 'integer'],
								'description' =>
									'The mode over a list: the objects of the memory of the client, as its '
									. 'cards name them. An object named twice is answered about once. A list '
									. 'longer than the ceiling of a page is refused rather than trimmed, '
									. 'because a list has no continuation to hand the rest over by.'
								,
							],
							self::PARAMETER_SCOPE_ROOT_ID => [
								'type' => 'integer',
								'description' =>
									'The mode over an area: the object the area is rooted at, as a change of '
									. 'access named it. Sent beside a checkToken it has to name the same area '
									. 'the token was issued for.'
								,
							],
							self::PARAMETER_CHECK_TOKEN => [
								'type' => 'string',
								'description' =>
									'Where the walk of the area stands. Absent from the first page - the '
									. 'portal opens the walk itself - and given back exactly as received on '
									. 'every page after it.'
								,
							],
							self::PARAMETER_LIMIT => [
								'type' => 'integer',
								'minimum' => 1,
								'maximum' => $limits->getPageSizeMax(),
								'description' =>
									'How many objects of the area are asked for, ' . $limits->getPageSizeMax()
									. ' by default. Of the mode over an area alone. A value above the ceiling '
									. 'is refused rather than trimmed. A page that asks for a heavy section is '
									. 'served at most ' . $limits->getPageSizeMaxHeavy() . ' objects - a '
									. 'shorter page, not a refusal - and a list of that many sections is '
									. 'bounded by the same number.'
								,
							],
							self::PARAMETER_SECTIONS => [
								'type' => 'array',
								'items' => ['type' => 'string', 'enum' => SectionsStructure::getDeclaredSections()],
								'description' =>
									'The sections of a card to fill on the visible objects of the answer. '
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
		return 'What is now true of the objects a client names, or of an area it names';
	}

	protected function getDescription(): ?string
	{
		return <<<'TEXT'
			Answers what the journal cannot: what a client still sees, after a position it can no
			longer read from and after a boundary of visibility moved.

			The method has two modes and the request chooses between them by what it carries. Name
			`objectIds` to ask about the objects the memory of the client already holds. Name
			`scopeRootId` to ask about an area - the root a change of access named. A request that
			names both is refused, and so is one that names neither: guessing which was meant would
			answer a question that was not asked.

			Over a list the answer holds exactly one item per object, in the order the objects were
			asked about. Over an area the answer is a page: ask for the next one with the
			`nextCheckToken` of the previous, and stop when `hasMore` says the area is over. A page
			that came back short is not the end of it - links inside an area collapse onto the object
			they point at - and only `hasMore` answers that question.

			An object the caller does not see is answered `visible: false` and carries no card. That
			answer is the same for an object hidden from the caller and for an object that was never
			there: the method never asks whether an object exists, so it cannot be used to find out.
			For the same reason an area whose root the caller cannot see is answered with an empty
			finished page rather than with a refusal - which is the ordinary case, not an edge one:
			the first move of a reconciliation runs right after a revocation, when the root is already
			invisible.

			An area whose root is perfectly visible answers the same way when everything in it changed
			too recently, and a client that reads an empty finished page as an empty area loses those
			objects for good. A page is read against a boundary of state standing a little behind the
			current instant, and what changed above that boundary enters no page of the walk. So an
			empty finished first move does not mean an empty area: repeat the first move on the next
			step of polling before treating the area as walked through. `safeWindowSeconds` is not that
			boundary - it describes the journal and the initial fetch, while a walk over an area is read
			against a narrower one of its own.

			A visible object may arrive without a card. It was deleted between the verdict that named
			it and the assembly that describes it; the client reads it back and waits for the journal
			to say otherwise, rather than dropping an object it has just been told it can see.

			The check answers about the state of right now. No depth of history is applied to it -
			bounded by depth it would say "you do not see it" about a visible object that simply had
			not changed for a long time - and objects in the trashcan are visible objects like any
			other. The consequence is accepted: after a check the memory of a client may hold objects
			older than the depth its session was given.

			The check moves no cursor, reads no journal and neither needs a session nor touches one.
			`checkToken` is its own kind of position with a boundary of state and a life of its own,
			and an expired one is refused with CHECK_TOKEN_EXPIRED - a code of its own, so that a
			client does not go and recreate a session the check never used. A token of another user,
			of another application of the same user, of another kind or damaged in any way is refused
			with CURSOR_INVALID.
			TEXT;
	}
}
