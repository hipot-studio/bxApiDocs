<?php
namespace Bitrix\ImOpenLines;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\FileTable;

/**
 * Avatar files uploaded in the queue editor of an open line.
 *
 * The mark in EXTERNAL_ID is both the proof that the file is an upload of the editor and the state
 * of that upload: it names the editor who uploaded the file until either the line that saves it or
 * the removal of it takes the file over. Both take it over by a conditional update of the mark, so
 * of two parallel requests reaching for the same upload only one gets it.
 */
class QueueAvatar
{
	public const MODULE_ID = 'imopenlines';
	public const STORAGE_PATH = 'imopenlines/queueavatars';
	public const STORAGE_MARK = 'imopenlines.queueavatar';

	private const REMOVED_MARK = self::STORAGE_MARK . '.removed';

	/** @var array<int, true>|null ids of the avatars the lines and their queues hold, read once */
	private static ?array $boundAvatarIds = null;

	/** @var array<int, true> files a line of this request has taken, bound right after the answer */
	private static array $boundWithinRequest = [];

	/**
	 * The transitions of the mark this request has written itself, as file id => [from, to]. Only
	 * these may be given back: the permissive answers about the files already carrying the mark of
	 * the line wrote nothing, and giving such a file back would hand an upload of another editing
	 * session to the editor of this request.
	 *
	 * @var array<int, array{from: string, to: string}>
	 */
	private static array $claimedWithinRequest = [];

	/**
	 * The mark a fresh upload of the editor carries. EXTERNAL_ID holds 50 characters, the mark of
	 * the storage takes 23 of them, so the id of the editor fits with room to spare.
	 */
	public static function getUploadMark(int $userId): string
	{
		return $userId > 0 ? self::STORAGE_MARK . '.user' . $userId : '';
	}

	/**
	 * Takes the upload over for removal and tells whether this request is the one that took it.
	 *
	 * Only an upload of the current editor bound to no line and no queue is deletable: an avatar
	 * already saved into a line is replaced or dropped by the form of the line, not by this action,
	 * and the upload of another editor is none of our business even while it is bound to nothing.
	 */
	public static function claimForRemoval(int $fileId): bool
	{
		$editorId = self::getCurrentEditorId();

		if ($fileId <= 0 || $editorId <= 0)
		{
			return false;
		}

		$uploadMark = self::getUploadMark($editorId);

		if (self::getMark($fileId) !== $uploadMark)
		{
			return false;
		}

		// nothing is remembered about the file afterwards: the mark it carries from here on is the one
		// of a removed upload, and both rules refuse such a mark
		return !self::isBound($fileId) && self::claim($fileId, $uploadMark, self::REMOVED_MARK);
	}

	/**
	 * Takes the upload over for the line and tells whether the file may be saved as an avatar of
	 * the line or of a row of its queue. The line is 0 while it is being created.
	 *
	 * The rule is milder than the one of removal: the avatar already bound to this line stays
	 * allowed, otherwise saving the form again would lose it. Avatars saved before november 2018
	 * lie outside of the storage path, so only this branch keeps them.
	 *
	 * The transition of the mark is written once per request, and the request that wrote it gets the
	 * same answer again: one photo given to several operators is an ordinary action of the editor, and
	 * every check of a queue runs before its rows are written. A file already carrying the mark of the
	 * line while nothing points to it is refused - only a hard death of the process leaves such a
	 * mark, and it is the same file the cleanup of orphans takes.
	 */
	public static function claimForBinding(int $fileId, int $configId): bool
	{
		if ($fileId <= 0)
		{
			return false;
		}

		if (self::isBoundToConfig($fileId, $configId))
		{
			return true;
		}

		$editorId = self::getCurrentEditorId();

		if ($editorId <= 0)
		{
			return false;
		}

		$uploadMark = self::getUploadMark($editorId);
		$lineMark = self::getLineMark($configId, $editorId);
		$mark = self::getMark($fileId);

		if ($mark !== $lineMark && $mark !== $uploadMark)
		{
			return false;
		}

		// the transition this request wrote itself is the only proof that the line asking is the one
		// holding the upload: the mark names the line that took it, not that the taking still stands.
		// A parallel request of the same line reads the same mark, and every creation of one editor
		// shares one mark, so equality alone would let a request pass on a transition it never wrote -
		// while the request that did write it may still give the upload back to its editor. During a
		// creation there is no number of a line yet, so no branch of the equality opens at all
		$takenByThisLine = $configId > 0
			&& (self::$claimedWithinRequest[$fileId]['to'] ?? '') === $lineMark
		;

		if ($mark === $lineMark && !$takenByThisLine)
		{
			return false;
		}

		// every other file may have been bound by a write that asked no rule and left the mark alone
		if (!$takenByThisLine && self::isBound($fileId))
		{
			return false;
		}

		if ($mark !== $lineMark)
		{
			if (!self::claim($fileId, $uploadMark, $lineMark))
			{
				return false;
			}

			self::rememberClaim($fileId, $uploadMark, $lineMark);
		}

		self::rememberBinding($fileId);

		return true;
	}

	/**
	 * The transitions of the mark written before this save started. A save that goes through drops
	 * its own transitions from the log of the rollback and leaves these alone, so it asks for them
	 * before it starts.
	 *
	 * @return array<int, true>
	 */
	public static function claimsOfEarlierSaves(): array
	{
		return array_fill_keys(array_keys(self::$claimedWithinRequest), true);
	}

	/**
	 * The transitions this save wrote are nobody's to give back any more: the record points at the
	 * files, or pointed at them and dropped them, and a dropped avatar is gone even when the row of
	 * the file outlives the delete by the control of duplicates.
	 *
	 * Several saves fit in one request - a rest batch sends them in one go - so without this a save
	 * refused later would give back a file of a save that went through and turn a record already
	 * deleted into a fresh upload of the editor: bindable again, and gone for good once the control
	 * of duplicates cleans the last of them.
	 *
	 * The bindings remembered for these files stay: the record does point at them.
	 *
	 * @param array<int, true> $claimsOfEarlierSaves the log as it was before this save started
	 */
	public static function keepWrittenClaims(array $claimsOfEarlierSaves): void
	{
		self::$claimedWithinRequest = array_intersect_key(self::$claimedWithinRequest, $claimsOfEarlierSaves);
	}

	/**
	 * Gives the uploads this save took over but stored nowhere back to the editor who uploaded them.
	 * Called by every path of a save that answers with an error: the rule of the record takes the
	 * upload over before the record is written, so a line refused afterwards - an invalid queue, a
	 * line that is not there, a failed write - would leave the file marked as one of the line while
	 * nothing points to it. Both rules refuse such a mark, so the tile of the form could never delete
	 * the file again and it would stay in b_file for good.
	 */
	public static function releaseUnwrittenClaims(): void
	{
		self::release(self::$claimedWithinRequest);
	}

	/**
	 * Gives the named uploads back to the editor who uploaded them: the rows they were taken over for
	 * are not written, while the save they belong to answers with success and keeps the rest of its
	 * claims.
	 *
	 * @param int[] $fileIds
	 */
	public static function releaseClaims(array $fileIds): void
	{
		self::release(array_intersect_key(self::$claimedWithinRequest, array_fill_keys($fileIds, true)));
	}

	/**
	 * Undoes the named transitions. The whole set is forgotten before the first of them is judged, so
	 * the bindings of the tables are read once for all of them and not once per file.
	 *
	 * @param array<int, array{from: string, to: string}> $claims
	 */
	private static function release(array $claims): void
	{
		foreach (array_keys($claims) as $fileId)
		{
			self::forgetClaim($fileId);
		}

		foreach ($claims as $fileId => $marks)
		{
			self::giveUploadBack($fileId, $marks);
		}
	}

	/**
	 * Drops what this request remembers about the transition, so that the file is judged by the tables
	 * alone: a record written between the transition and here makes the file a living avatar, and the
	 * answers remembered within the request count the transition itself as a binding.
	 */
	private static function forgetClaim(int $fileId): void
	{
		unset(self::$claimedWithinRequest[$fileId], self::$boundWithinRequest[$fileId]);
		self::$boundAvatarIds = null;
	}

	/**
	 * Undoes the transition of the mark. A file bound by a write that did go through stays where it
	 * is, and so does an upload another request has taken over in the meantime: the condition of the
	 * update tells.
	 *
	 * @param array{from: string, to: string} $marks
	 */
	private static function giveUploadBack(int $fileId, array $marks): void
	{
		if (!self::isBound($fileId))
		{
			self::claim($fileId, $marks['to'], $marks['from']);
		}
	}

	/**
	 * Drops the answers remembered within the request.
	 */
	public static function cleanBindingCache(): void
	{
		self::$boundAvatarIds = null;
		self::$boundWithinRequest = [];
		self::$claimedWithinRequest = [];
	}

	/**
	 * The mark the file carries, empty for anything that is not an upload of the avatar editor.
	 * MODULE_ID alone holds no border - files of widget clients carry it too - and the path holds
	 * none either: with the control of duplicates on, CFile::SaveFile() gives a new record the path
	 * of the byte identical original, which may lie outside of the storage.
	 */
	private static function getMark(int $fileId): string
	{
		$file = \CFile::getByID($fileId)->fetch();

		if (
			empty($file)
			|| (string)$file['MODULE_ID'] !== self::MODULE_ID
		)
		{
			return '';
		}

		$mark = (string)($file['EXTERNAL_ID'] ?? '');

		return str_starts_with($mark, self::STORAGE_MARK . '.') ? $mark : '';
	}

	private static function getCurrentEditorId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	/**
	 * The state the upload passes into once a line takes it. A line being created has no number yet,
	 * so its state names the editor who creates it: a state every creation would share lets one
	 * creation pass on the upload another one has already taken.
	 */
	private static function getLineMark(int $configId, int $editorId): string
	{
		return $configId > 0
			? self::STORAGE_MARK . '.line' . $configId
			: self::STORAGE_MARK . '.new' . $editorId
		;
	}

	/**
	 * The transition of the mark: hands the file over from the state it is expected to carry to the
	 * next one. The answer is the number of rows the update changed and not the mark read before it,
	 * so a request that lost the upload to a parallel one a moment ago learns it here.
	 */
	private static function claim(int $fileId, string $expectedMark, string $newMark): bool
	{
		$connection = Application::getConnection();

		$connection->queryExecute(
			(new SqlExpression(
				'UPDATE ?# SET ?# = ?s WHERE ?# = ?i AND ?# = ?s AND ?# = ?s',
				FileTable::getTableName(),
				'EXTERNAL_ID',
				$newMark,
				'ID',
				$fileId,
				'MODULE_ID',
				self::MODULE_ID,
				'EXTERNAL_ID',
				$expectedMark
			))->compile()
		);

		$claimed = $connection->getAffectedRowsCount() === 1;

		if ($claimed)
		{
			\CFile::cleanCache($fileId);
		}

		return $claimed;
	}

	/**
	 * Counts the file as bound from here on: the caller binds it right after the permissive answer,
	 * while the set read from the tables was read before that and knows nothing about it.
	 */
	private static function rememberBinding(int $fileId): void
	{
		self::$boundWithinRequest[$fileId] = true;
	}

	/**
	 * Keeps the transition of the mark this request wrote, so that it can be undone while the record
	 * it was written for has not been stored.
	 */
	private static function rememberClaim(int $fileId, string $fromMark, string $toMark): void
	{
		self::$claimedWithinRequest[$fileId] = ['from' => $fromMark, 'to' => $toMark];
	}

	/**
	 * The bindings are read as a whole and once per request: a check of a single file walks every line
	 * of the portal, so a queue holding several new avatars used to walk them all over again for each
	 * of them.
	 */
	private static function isBound(int $fileId): bool
	{
		if (isset(self::$boundWithinRequest[$fileId]))
		{
			return true;
		}

		self::$boundAvatarIds ??= self::readBoundAvatarIds();

		return isset(self::$boundAvatarIds[$fileId]);
	}

	/**
	 * The ids of the avatars of the rows of the queues and of the default operators of the lines. The
	 * ids alone are kept, so a portal of thousands of lines costs a set of numbers and not of rows.
	 *
	 * Nothing here is limited: a truncated read would leave a bound avatar looking free and let a
	 * request delete it.
	 *
	 * @return array<int, true>
	 */
	private static function readBoundAvatarIds(): array
	{
		$avatarIds = [];

		$queueRows = Model\QueueTable::getList([
			'select' => ['USER_AVATAR_ID'],
			'filter' => ['>USER_AVATAR_ID' => 0],
		]);
		while ($queueRow = $queueRows->fetch())
		{
			$avatarIds[(int)$queueRow['USER_AVATAR_ID']] = true;
		}

		// DEFAULT_OPERATOR_DATA is a serialized field, so its avatar is read in php
		$configs = Model\ConfigTable::getList([
			'select' => ['DEFAULT_OPERATOR_DATA'],
		]);
		while ($config = $configs->fetch())
		{
			$avatarId = self::getDefaultOperatorAvatarId($config['DEFAULT_OPERATOR_DATA']);

			if ($avatarId > 0)
			{
				$avatarIds[$avatarId] = true;
			}
		}

		return $avatarIds;
	}

	/**
	 * The binding is read the way readBoundAvatarIds() reads it - without the cache of the orm entity:
	 * the milder rule of the record must not decide on data older than the strict rule of removal sees.
	 */
	private static function isBoundToConfig(int $fileId, int $configId): bool
	{
		if ($configId <= 0)
		{
			return false;
		}

		$config = Model\ConfigTable::getByPrimary($configId, [
			'select' => ['DEFAULT_OPERATOR_DATA'],
		])->fetch();

		if (self::getDefaultOperatorAvatarId($config['DEFAULT_OPERATOR_DATA'] ?? null) === $fileId)
		{
			return true;
		}

		return self::hasQueueRow(['=USER_AVATAR_ID' => $fileId, '=CONFIG_ID' => $configId]);
	}

	private static function hasQueueRow(array $filter): bool
	{
		$queueRow = Model\QueueTable::getList([
			'select' => ['ID'],
			'filter' => $filter,
			'limit' => 1,
		])->fetch();

		return !empty($queueRow);
	}

	private static function getDefaultOperatorAvatarId(mixed $defaultOperatorData): int
	{
		if (!is_array($defaultOperatorData))
		{
			return 0;
		}

		return (int)($defaultOperatorData['AVATAR_ID'] ?? 0);
	}
}
