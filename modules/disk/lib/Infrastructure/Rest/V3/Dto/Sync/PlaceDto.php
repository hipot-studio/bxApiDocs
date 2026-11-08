<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync;

use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * One place a user sees an object at (DTO-02).
 *
 * An object has as many places as there are ways to reach it: its own folder, every link of the
 * user pointing at it, and every folder above it that the user reaches through a link of theirs. An
 * empty list of places is a normal state and says nothing about visibility - an object visible
 * through interaction alone sits in no storage of the user and still has to be synchronised.
 *
 * The state of a place belongs to the place and not to the object: a link carries its own name and
 * its own mark of deletion, so the same file may be live in one place and in the trashcan in
 * another.
 *
 * Built by hand by the collector of places rather than mapped from a stored row, so it declares
 * neither an ORM entity nor a mapper. Property order is the order of the published answer.
 */
final class PlaceDto extends Dto
{
	public int $storageId;

	/**
	 * The folder the object lies in at this place. Where the parent is a shared folder reached
	 * through a link, this is the identifier of the link - the node the client keeps in its tree.
	 *
	 * Answered even when the folder itself is above the boundary of access, which is what makes an
	 * empty path meaningful: the client learns that the object is somewhere it cannot describe. An
	 * identifier is not a name, and no name of such a folder is published anywhere.
	 */
	public int $parentFolderId;

	/**
	 * The folders from the top of the storage down to the parent, in that order, and only those the
	 * user may read. Where the path crosses the boundary of access it is cut to the nearest readable
	 * ancestor; the names above the cut are not published in any form.
	 *
	 * @var PathNodeDto[]
	 */
	#[ElementType(PathNodeDto::class)]
	public array $path;

	/**
	 * Whether the path reaches the top of the storage. An empty path with this set to false is a
	 * legal answer and means the whole path lies above the boundary of access.
	 */
	public bool $pathComplete;

	/**
	 * Whether the way to this place runs through a link of the user rather than through folders
	 * alone. It speaks of the road to the place and not of the object: one and the same object is
	 * taken by a link in one place and lies plainly in a folder of the user in another.
	 *
	 * Told because the client cannot work it out from the rest of the card, and it needs to: a place
	 * reached by a link names the link in its path, while a change of access names the real folder
	 * the rights live on. Such a place matches no area by path, and the object behind it has to be
	 * asked about by name instead of being looked for under the root of the area.
	 */
	public bool $reachedByLink;

	public bool $trashed;

	/**
	 * Whether the object went to the trashcan with an ancestor rather than on its own. Only
	 * meaningful while trashed, and needed for restoring: a folder brought back must leave behind
	 * those of its descendants that were thrown away before it.
	 */
	public bool $trashedWithAncestor;
}
