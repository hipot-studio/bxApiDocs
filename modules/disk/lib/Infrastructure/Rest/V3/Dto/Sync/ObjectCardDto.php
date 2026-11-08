<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * One object as the personal memory of a client keeps it (DTO-01).
 *
 * The card carries what such a memory needs to find a file and to show it, and stops there. It is
 * the contract of the whole contour and grows only by addition: a field once answered keeps its
 * meaning, and a new one is a decision of the owner of the feature rather than of the code that
 * fills the card in.
 *
 * A field that does not apply is absent from the answer, never present and empty. A folder has no
 * size and no version, an object whose content cannot be fetched has no identifier to fetch it by,
 * and a card built without the section of location says nothing about where the object lies - in
 * each case the property is left uninitialised and {@see Dto::toArray()} drops the key. An empty
 * value would be worse than silence: a client would take it for an answer and act on it.
 *
 * Property order is the order of the published answer.
 */
final class ObjectCardDto extends Dto
{
	public const TYPE_FILE = 'file';
	public const TYPE_FOLDER = 'folder';

	public string $type;

	/**
	 * Always the real object and never a link to it. A link is a place the object is seen at, and
	 * lives in {@see PlaceDto}: it has a name of its own and a state of its own, while the content
	 * behind it is the same. Keyed this way, a new version of a file is one change for the client
	 * instead of one per link pointing at it.
	 */
	public int $objectId;

	public string $name;

	public string $extension;

	public string $mimeType;

	/** Size of the content in bytes. */
	public int $size;

	/**
	 * The stored version the content of the card belongs to, separate from the object it belongs to:
	 * the two are asked for by different methods and must not be confused for one another.
	 */
	public int $versionId;

	/**
	 * How many times the content has changed since the file appeared. Separate from the identifier of
	 * the version, and the field a client compares to learn that a file it already holds is stale.
	 */
	public int $versionNumber;

	public DateTime $createdAt;

	/**
	 * When the object last changed in a way its owner would recognise - not when synchronisation last
	 * touched the row. Moving a folder rewrites the mark of synchronisation across everything below
	 * it in one statement, and a client ordering its memory by that would see a thousand files it
	 * knows well as the newest thing on the portal.
	 */
	public DateTime $updatedAt;

	public EntityRefDto $createdBy;

	public EntityRefDto $updatedBy;

	/**
	 * Who owns the storage the real object lies in. Not one of the places: an object reachable from
	 * several storages still lives in exactly one, and that one may be a storage the reader cannot
	 * see at all.
	 */
	public EntityRefDto $storageOwner;

	/**
	 * Whether the content of the file may be fetched. Answered pessimistically: everything visible
	 * by anything other than the rights of Disk is answered as unavailable, without asking the
	 * connectors of neighbouring modules object by object.
	 *
	 * There is no separate right to content in the model of rights - downloading is closed by the
	 * same canRead as reading the metadata. What differs is the identity of the resource: an object
	 * visible through interaction is fetched by the identifier of its attachment, not by the object
	 * itself, and only the module that owns the attachment can tell whether that is allowed.
	 */
	public bool $contentAvailable;

	/**
	 * What to ask disk.sync.content.get for the bytes. Present only while the content is available;
	 * where it is not, the field is absent rather than empty, because an empty one is something a
	 * client would try to use.
	 */
	public string $downloadId;

	/**
	 * Every place the reader sees this object at. An empty list is a normal answer and says nothing
	 * about visibility - an object visible through interaction alone lies in no storage of theirs.
	 * Absent entirely when the card was not asked for the section of location.
	 *
	 * @var PlaceDto[]
	 */
	#[ElementType(PlaceDto::class)]
	public array $places;

	/**
	 * Whether a place is still to come: the reader was invited to this object or to a folder above
	 * it and has not answered the invitation yet, so accepting it puts the object somewhere they
	 * see. Answered for every object of the section and not only for the ones it is true of - an
	 * object that already has a place is answered false rather than left out, because absence is
	 * taken by "the section was not asked for" and a field absent for two reasons would let a
	 * client read "the portal cannot answer this yet" as "the question does not apply".
	 *
	 * An empty list of places says nothing about visibility, and this field is what tells the three
	 * states behind it apart: an invitation not yet accepted is the only one of them a place is
	 * still coming for. Absent entirely, beside the list of places, when the card was not asked for
	 * the section of location.
	 */
	public bool $placeExpected;
}
