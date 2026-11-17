<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internals\MailboxDirectoryTable;

/**
 * The system roles of the folders of a mailbox, read once and answered for every generation
 * it has: which role the folder of a given hash holds, and which folder of a generation holds
 * a given role.
 *
 * Both questions used to be answered on the spot and for one role only. They belong together
 * because a transfer asks them of two generations at once - the role of a folder comes from
 * the side still serving the user, the folder holding it from the side about to.
 *
 * A folder is addressed by its hash and never by its path: the path of a folder goes into that
 * hash irreversibly, and two generations of one mailbox may spell the same role differently -
 * Sent against Outbox, Trash against Bin.
 *
 * A folder holding no system role is an ordinary answer of this map and not a failure: a folder
 * of the user is exactly that, and the whole point of asking is to tell one from the other.
 */
final class SystemRoleMap
{
	/**
	 * The roles a transfer addresses, in the order a folder holding more than one is read by.
	 *
	 * Drafts are deliberately not among them - nothing guarantees a draft folder on either
	 * source - and an archive is no role of this module at all.
	 */
	public const ROLES = [
		MailboxDirectoryTable::TYPE_INCOME,
		MailboxDirectoryTable::TYPE_OUTCOME,
		MailboxDirectoryTable::TYPE_TRASH,
		MailboxDirectoryTable::TYPE_SPAM,
	];

	/** @var array<int, array<string, string[]>> Generation => folder hash => the roles it declares */
	private array $rolesOfFolder = [];

	/** @var array<int, array<string, array{path: string, hash: string}>> Generation => role => its folder */
	private array $folderOfRole = [];

	/**
	 * @var array<int, array<string, true>> Generation => the hashes of the folders declaring drafts.
	 *      Kept apart from the roles above, so the rules built on {@see ROLES} never see a draft folder.
	 */
	private array $draftFolders = [];

	private function __construct(array $rows)
	{
		foreach ($rows as $row)
		{
			$generationId = (int)$row['GENERATION_ID'];
			$hash = (string)$row['DIR_MD5'];

			if ($hash === '')
			{
				continue;
			}

			if ((int)($row[MailboxDirectoryTable::TYPE_DRAFT] ?? 0) === MailboxDirectoryTable::ACTIVE)
			{
				$this->draftFolders[$generationId][$hash] = true;
			}

			$roles = self::rolesOfRow($row);
			if ($roles === [])
			{
				continue;
			}

			$this->rolesOfFolder[$generationId][$hash] = $roles;

			foreach ($roles as $role)
			{
				// The first folder of a role wins, as it does for the folder helper of a mailbox
				$this->folderOfRole[$generationId][$role] ??= [
					'path' => (string)$row['PATH'],
					'hash' => $hash,
				];
			}
		}
	}

	/**
	 * The folders of the whole mailbox, every generation of it included: one read answers a
	 * page of letters whose two sides live in two different generations.
	 */
	public static function ofMailbox(int $mailboxId): self
	{
		if ($mailboxId <= 0)
		{
			return new self([]);
		}

		return new self(MailboxDirectoryTable::getList([
			'select' => ['GENERATION_ID', 'DIR_MD5', 'PATH', ...self::ROLES, MailboxDirectoryTable::TYPE_DRAFT],
			'filter' => ['=MAILBOX_ID' => $mailboxId],
			/*
				The order a role is resolved by when several folders declare it, kept the same
				as the one the folder helper groups by, so both name the same folder.
			*/
			'order' => ['LEVEL' => 'ASC', 'ID' => 'ASC'],
		])->fetchAll());
	}

	/**
	 * The map of folder rows already read by the caller.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows of b_mail_mailbox_dir with GENERATION_ID,
	 *        DIR_MD5, PATH and the role flags, IS_DRAFT among them, in the order a role is resolved by.
	 */
	public static function fromRows(array $rows): self
	{
		return new self($rows);
	}

	/**
	 * Whether that folder declares drafts - a role apart from {@see ROLES}, asked only by the
	 * placement of a letter in the folder of the same role.
	 */
	public function holdsDrafts(int $generationId, string $dirHash): bool
	{
		return isset($this->draftFolders[$generationId][$dirHash]);
	}

	/**
	 * @return string|null The role of that folder, null for a folder of the user. A folder
	 *         declaring several of them answers with the first one of {@see ROLES}.
	 */
	public function roleOf(int $generationId, string $dirHash): ?string
	{
		return $this->rolesOfFolder[$generationId][$dirHash][0] ?? null;
	}

	/**
	 * Whether that folder declares that role, which is a different question from the one
	 * above for the rare folder that declares more than one.
	 */
	public function holds(int $generationId, string $dirHash, string $role): bool
	{
		return in_array($role, $this->rolesOfFolder[$generationId][$dirHash] ?? [], true);
	}

	/**
	 * @return array{path: string, hash: string}|null The folder holding that role in that
	 *         generation, null when the generation has none.
	 */
	public function folderOf(int $generationId, string $role): ?array
	{
		return $this->folderOfRole[$generationId][$role] ?? null;
	}

	/**
	 * @return string|null The path of the folder holding that role, as the IMAP commands of
	 *         the module spell it.
	 */
	public function pathOf(int $generationId, string $role): ?string
	{
		return $this->folderOf($generationId, $role)['path'] ?? null;
	}

	/**
	 * @return string[] The roles the folder row declares, in the order of {@see ROLES}.
	 */
	private static function rolesOfRow(array $row): array
	{
		$roles = [];

		foreach (self::ROLES as $role)
		{
			if ((int)($row[$role] ?? 0) === MailboxDirectoryTable::ACTIVE)
			{
				$roles[] = $role;
			}
		}

		return $roles;
	}
}
