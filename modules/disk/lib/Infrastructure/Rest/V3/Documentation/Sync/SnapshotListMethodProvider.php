<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Documentation\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\ObjectCardDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\CursorInvalidException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\FeatureNotSupportedException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SnapshotTokenExpiredException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SyncAccessDeniedException;
use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\StrictRequest;
use Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync\SectionsStructure;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Schema\TypeAliasRegistry;

/**
 * Documentation of disk.sync.snapshot.list.
 */
final class SnapshotListMethodProvider extends SyncMethodProvider
{
	private const PARAMETER_SNAPSHOT_TOKEN = 'snapshotToken';
	private const PARAMETER_LIMIT = 'limit';
	private const PARAMETER_SECTIONS = 'sections';

	protected function getItemDto(): Dto
	{
		return ObjectCardDto::create();
	}

	/**
	 * One refusal of the validation layer and no more: every one of them is published under the same
	 * code, so a second example would only repeat that code in the schema while replacing the example
	 * of the first. The one kept is the refusal this method has of its own; the other two are named in
	 * the description of the parameters they are about.
	 */
	protected function getRefusals(): array
	{
		return [
			new FeatureNotSupportedException(),
			new SyncAccessDeniedException(),
			new SnapshotTokenExpiredException(),
			new CursorInvalidException(),
			StrictRequest::refusal(
				self::PARAMETER_LIMIT,
				'DISK_SYNC_REQUEST_LIMIT_TOO_LARGE',
				['#MAX#' => (string)(new SyncLimits())->getPageSizeMax()],
			),
		];
	}

	/**
	 * The page, and not the single-item envelope of the rest of the contour: the folders travel in a
	 * section of their own, and the position of the next page travels with them.
	 */
	protected function describeSuccess(): array
	{
		$cardReference = ['$ref' => '#/components/schemas/' . TypeAliasRegistry::toPublicType($this->getItemDto())];

		return [
			'description' => 'One page of the initial fetch.',
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
										'description' => 'The objects of the page.',
										'items' => $cardReference,
									],
									'folders' => [
										'type' => 'array',
										'description' =>
											'Every folder the page needs the client to know: the folders its '
											. 'objects hang under, as far up as the caller may read, and the '
											. 'links of the caller that the paths of the page pass through. '
											. 'Not counted into the size of the page.'
										,
										'items' => $cardReference,
									],
									'hasMore' => [
										'type' => 'boolean',
										'description' => 'Whether the fetch goes on.',
									],
									'nextSnapshotToken' => [
										'type' => 'string',
										'description' =>
											'The position the next page is asked for by. Answered on the '
											. 'last page as well.'
										,
									],
									'warnings' => [
										'type' => 'array',
										'description' => 'What this page could not deliver. Empty in the usual case.',
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
						'required' => [self::PARAMETER_SNAPSHOT_TOKEN],
						'properties' => [
							self::PARAMETER_SNAPSHOT_TOKEN => [
								'type' => 'string',
								'description' =>
									'Where the fetch stands. Opaque: it comes from '
									. 'disk.sync.session.create and then from every page, and is given back '
									. 'exactly as it was received.'
								,
							],
							self::PARAMETER_LIMIT => [
								'type' => 'integer',
								'minimum' => 1,
								'maximum' => $limits->getPageSizeMax(),
								'description' =>
									'How many objects of the page are asked for, ' . $limits->getPageSizeMax()
									. ' by default. A value above the ceiling is refused rather than trimmed. '
									. 'A page that asks for a heavy section is served at most '
									. $limits->getPageSizeMaxHeavy() . ' objects - a shorter page, not a '
									. 'refusal. Both ceilings are published by disk.sync.capabilities.get.'
								,
							],
							self::PARAMETER_SECTIONS => [
								'type' => 'array',
								'items' => ['type' => 'string', 'enum' => SectionsStructure::getDeclaredSections()],
								'description' =>
									'The sections of a card to fill. Everything this portal serves when the '
									. 'parameter is absent. A section the portal declares but does not fill '
									. 'is refused when asked for rather than answered empty, and so is a '
									. 'parameter the schema does not declare at all.'
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
		return 'One page of the initial fetch';
	}

	protected function getDescription(): ?string
	{
		return <<<'TEXT'
			Hands over the personal copy of a client page by page, in the order the client needs it
			rather than in the order the portal stores it.

			The page is read against the state the session fixed, so a page is repeatable: an object
			that changes while the fetch is running neither moves between pages nor falls out of it.
			Ask for the next page with the `nextSnapshotToken` of the previous one, and stop when
			`hasMore` says the fetch is over. A page that came back short is not the end of the fetch -
			rows are lost to the collapsing of links and to the confirmation of the interaction set,
			and only `hasMore` says whether anything is left.

			`folders` is a section of its own and is not counted into `limit`. Folders join the fetch
			by kinship - as the readable folders above the objects of the page - and not by their own
			freshness: the date a folder changed says nothing about the activity inside it, and a
			folder untouched for years may well hold the files a client asked for. Folders are
			deduplicated inside a page; a folder met again on a later page is normal and costs the
			client nothing, because applying the card of a folder is idempotent.

			Every folder a path of the page names has a card in `folders`. A folder of somebody else
			that the caller reaches through a link of their own enters the path as that link, under
			the link's own name, and the card of that link is answered beside the ancestors. The one
			identifier that may be answered without a card is `parentFolderId` of a place whose path
			does not reach it: the folder then lies above the boundary of access, and no name of it is
			published anywhere.

			`snapshotToken` stops being accepted at the `snapshotTokenExpiresAt` of its session, and a
			fetch that did not finish by then is started again with a new session rather than stitched
			onto the old one. The same refusal answers a token of a session that has since been
			replaced - opening a session again annuls the fetch of the previous one.

			`warnings` names what the page could not deliver: an object deleted between the fetch that
			named it and the assembly that describes it arrives as a warning rather than as a card.
			The identifier is part of it, because that is what lets a client read the object back
			instead of discovering the gap by comparing counts.
			TEXT;
	}
}
