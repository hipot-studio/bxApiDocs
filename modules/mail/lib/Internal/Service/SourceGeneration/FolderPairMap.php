<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Main\Text\Emoji;

/**
 * The folders of the user a transfer brought into the new server itself, each with the folder
 * of the old server it stands for {@see MigrationService::STATE_FOLDER_PAIRS}.
 *
 * Both sides are addressed by the hash of the path, as {@see SystemRoleMap} addresses them. A pair
 * one side of which has no row any more - the folder was deleted on the old server - is simply
 * not in the map, and neither is a target the import of the prepared generation does not read:
 * a letter put there would never be read back.
 */
final class FolderPairMap
{
	/**
	 * @var array<string, array{path: string, hash: string, created: bool}> Source folder hash => its target.
	 *      created tells a folder the transfer created from one it found already there.
	 */
	private array $targetOfSource = [];

	private function __construct(
		private readonly int $preparedGenerationId,
		private readonly GenerationScope $sourceScope,
	)
	{
	}

	public static function ofGeneration(int $mailboxId, int $generationId): self
	{
		$map = new self($generationId, GenerationScope::forMailbox($mailboxId));

		$generation = MailboxSourceGenerationTable::getRow([
			'select' => ['OPTIONS'],
			'filter' => ['=ID' => $generationId, '=MAILBOX_ID' => $mailboxId],
		]);
		$options = is_array($generation['OPTIONS'] ?? null) ? $generation['OPTIONS'] : [];

		$targetPathOfSource = [];
		$createdOfSource = [];
		foreach ((array)($options[MigrationService::STATE_FOLDER_PAIRS] ?? []) as $pair)
		{
			if (is_array($pair) && (string)($pair['source'] ?? '') !== '' && (string)($pair['target'] ?? '') !== '')
			{
				$sourceHash = self::hashOf((string)$pair['source']);
				$targetPathOfSource[$sourceHash] = (string)$pair['target'];
				// Pairs written before found folders were paired at all name created folders only
				$createdOfSource[$sourceHash] = (bool)($pair['created'] ?? true);
			}
		}

		if ($targetPathOfSource === [])
		{
			return $map;
		}

		$sources = MailboxDirectoryTable::getList([
			'select' => ['DIR_MD5'],
			'filter' => $map->sourceScope->apply([
				'=MAILBOX_ID' => $mailboxId,
				'!=GENERATION_ID' => $generationId,
				'@DIR_MD5' => array_keys($targetPathOfSource),
			]),
		])->fetchAll();

		$targets = MailboxDirectoryTable::getList([
			'select' => ['DIR_MD5', 'PATH'],
			'filter' => GenerationScope::exact($generationId)->apply([
				'=MAILBOX_ID' => $mailboxId,
				'@DIR_MD5' => array_map(self::hashOf(...), array_values($targetPathOfSource)),
				'=IS_SYNC' => MailboxDirectoryTable::ACTIVE,
			]),
		])->fetchAll();

		$targetOfHash = [];
		foreach ($targets as $row)
		{
			$targetOfHash[(string)$row['DIR_MD5']] = ['path' => (string)$row['PATH'], 'hash' => (string)$row['DIR_MD5']];
		}

		foreach ($sources as $row)
		{
			$sourceHash = (string)$row['DIR_MD5'];
			$target = $targetOfHash[self::hashOf($targetPathOfSource[$sourceHash])] ?? null;

			if ($target !== null)
			{
				$map->targetOfSource[$sourceHash] = $target + ['created' => $createdOfSource[$sourceHash]];
			}
		}

		return $map;
	}

	/**
	 * The map of pairs already resolved by the caller, the source being any generation but the
	 * prepared one.
	 *
	 * @param array<string, array{path: string, hash: string, created?: bool}> $targetOfSource
	 *        Source folder hash => its target, a target without created being a created folder.
	 */
	public static function fromTargets(int $preparedGenerationId, array $targetOfSource): self
	{
		$map = new self($preparedGenerationId, GenerationScope::unfiltered());

		foreach ($targetOfSource as $sourceHash => $target)
		{
			$map->targetOfSource[(string)$sourceHash] = [
				'path' => (string)$target['path'],
				'hash' => (string)$target['hash'],
				'created' => (bool)($target['created'] ?? true),
			];
		}

		return $map;
	}

	/**
	 * @return array{path: string, hash: string, created: bool}|null The folder of the prepared
	 *         generation the folder of the old server is paired with, null for any other folder.
	 *         created is false for a folder the transfer found on the new server rather than made.
	 */
	public function targetOf(int $sourceGenerationId, string $sourceDirHash): ?array
	{
		if ($sourceGenerationId === $this->preparedGenerationId || !$this->sourceScope->includes($sourceGenerationId))
		{
			return null;
		}

		return $this->targetOfSource[$sourceDirHash] ?? null;
	}

	private static function hashOf(string $path): string
	{
		return md5(Emoji::encode($path));
	}
}
