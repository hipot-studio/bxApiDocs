<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Journal;

/**
 * What a record of the journal reports.
 *
 * The vocabulary is the one the portal publishes in disk.sync.capabilities.get and is kept identical to
 * it, value for value. A client plans its branches on that answer, so a type the journal writes and the
 * answer never names is a branch nobody takes, and a type the answer names and the journal never writes
 * is a branch nobody reaches.
 *
 * Seven of them are the ways an object enters or changes inside what a client already holds, and they
 * are the ones a domain method chooses between. The remaining two the writer sets itself and no caller
 * passes: an object leaving the sight of its addressee, and a boundary of visibility that moved without
 * any object changing at all.
 *
 * An object entering an interaction set is published as an ordinary update. It has no value of its own
 * because a client needs no branch for it - it is handed a card by an identifier it never held and adds
 * it - and what makes such a record addressed to one person is its single personal code.
 *
 * The type is what a record is read by, not what it is acted on: a card is assembled from the object
 * as it stands now, so a client that missed the difference between a move and a rename loses nothing.
 */
enum ChangeType: string
{
	case Created = 'created';
	case Updated = 'updated';
	case ContentVersion = 'contentVersion';
	case Renamed = 'renamed';
	case Moved = 'moved';
	case Trashed = 'trashed';
	case Restored = 'restored';
	case Deleted = 'deleted';
	case AccessChanged = 'accessChanged';

	/**
	 * Whether a domain mutation may publish this type through
	 * {@see JournalWriter::publishObjectChange()}. The two the writer sets itself have methods of their
	 * own, and reaching them through the general one would let a caller write a tombstone without a
	 * reason or a change of access without a scope.
	 *
	 * The open set is named and not the closed one: a type added later is refused until it is thought
	 * about, and a record that was not written costs its client one reconciliation, while a record of a
	 * shape that cannot be read costs it the journal.
	 */
	public function isPublishableByDomain(): bool
	{
		return match ($this)
		{
			self::Created, self::Updated, self::ContentVersion, self::Renamed,
			self::Moved, self::Trashed, self::Restored => true,
			default => false,
		};
	}
}
