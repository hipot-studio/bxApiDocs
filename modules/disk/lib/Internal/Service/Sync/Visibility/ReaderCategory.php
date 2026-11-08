<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Visibility;

use Bitrix\Intranet;
use Bitrix\Main\Loader;
use Throwable;

/**
 * Whether a row of the interaction set may still be confirmed for this reader by a source of access
 * that is not the rights of Disk.
 *
 * Of the sources {@see AccessSourceConfirmer} asks, only the rights of Disk are personal: a link lives
 * on the object and answers for whoever holds it, and an attachment is counted by the identifier the
 * row carries. A reader of the extranet or of a collab is the one such an answer must not be given to -
 * a link of somebody else, or an attachment into an entity of a module he is no addressee of, would go
 * on handing him the card of the object and the stream of its changes after the rights of Disk have
 * been taken away.
 *
 * The two are recognised by a role the portal hands out on a trait of its own - membership of the
 * extranet, membership of a collab ({@see Intranet\Enum\UserRole}) - and never by the absence of one.
 * "Not an employee of the company" would have named a far wider crowd than the two: an account outside
 * the structure is also every integration that speaks to this contour through REST, and it would have
 * lost by the narrowing what it is here to read.
 *
 * A reader whose role cannot be told - the module of the portal absent, no account behind the
 * identifier, a question that threw - is not narrowed. The role is asked to take a confirmation away,
 * and one that went unanswered is no ground to take it.
 */
class ReaderCategory
{
	/**
	 * The role of a reader does not change within a request, and a page asks it once.
	 *
	 * @var array<int, bool>
	 */
	private array $answered = [];

	public function allowsSourcesBeyondDiskRights(int $userId): bool
	{
		if ($userId <= 0)
		{
			return true;
		}

		return $this->answered[$userId] ??= !self::isNarrowed($userId);
	}

	private static function isNarrowed(int $userId): bool
	{
		try
		{
			if (!Loader::includeModule('intranet'))
			{
				return false;
			}

			return match ((new Intranet\User($userId))->getUserRole())
			{
				Intranet\Enum\UserRole::EXTRANET, Intranet\Enum\UserRole::COLLABER => true,
				default => false,
			};
		}
		catch (Throwable)
		{
			return false;
		}
	}
}
