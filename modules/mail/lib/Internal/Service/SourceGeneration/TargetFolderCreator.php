<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Imap;

/**
 * Brings one folder of the user into the target server of a migration: finds it there or creates it.
 *
 * One folder per call, its parent already resolved: the chain of ancestors is walked top down by the
 * caller. Every command is preceded by an addressed lookup and every success is confirmed by one, so a
 * folder the server copied on its own or a repeat after a broken pass ends in a pair without a CREATE.
 */
class TargetFolderCreator
{
	public const OUTCOME_PAIR = 'pair';
	public const OUTCOME_NOT_CREATED = 'not_created';
	/** The server may answer otherwise later: the whole inspection is failed and repeated */
	public const OUTCOME_RETRY = 'retry';
	/** The CREATE command failed temporarily or did not answer; the current connection is not reused */
	public const OUTCOME_CREATE_FAILED = 'create_failed';

	public const REASON_UNREPRESENTABLE = 'unrepresentable';
	public const REASON_FLAT_NAMESPACE = 'flat_namespace';
	public const REASON_DELIMITER_IN_NAME = 'delimiter_in_name';
	public const REASON_NOT_VISIBLE = 'not_visible';
	public const REASON_NAME_COLLISION = 'name_collision';
	public const REASON_PARENT_NOT_CREATED = 'parent_not_created';
	/** The observer could not write the folder down, so it is not created {@see AcceptanceFolderLedger::record()} */
	public const REASON_NOT_REMEMBERED = 'not_remembered';
	public const REASON_REFUSED_PREFIX = 'refused:';

	private const FINAL_REFUSALS = [
		'NOPERM',
		'CANNOT',
		'LIMIT',
		'OVERQUOTA',
		'CLIENTBUG',
		'NONEXISTENT',
		'AUTHORIZATIONFAILED',
		'AUTHENTICATIONFAILED',
		'PRIVACYREQUIRED',
		'EXPIRED',
	];
	private const MINIMUM_COMMAND_TIMEOUT = 5;

	/**
	 * @param int|null $deadline The wall clock after which no further folder is taken up; a folder
	 *        left over is the hand over's {@see LateFolderReconciler}.
	 * @param \Closure(string, string): (bool|void)|null $onCreating Told of every folder about to be
	 *        created, the source path and the target name, before the CREATE goes out: a CREATE whose
	 *        answer or confirming lookup is lost may still have made the folder. False keeps the folder
	 *        from being created.
	 */
	public function __construct(
		private readonly ?int $deadline = null,
		private readonly ?\Closure $onCreating = null,
	)
	{
	}

	public function hasTimeLeft(): bool
	{
		return $this->deadline === null || time() < $this->deadline;
	}

	private function reportCreating(string $sourcePath, string $targetName): bool
	{
		return $this->onCreating === null || ($this->onCreating)($sourcePath, $targetName) !== false;
	}

	/**
	 * @param array{name: string, delim: string}|null $parent The target folder of the parent, null for a root.
	 * @param string|null $rootDelimiter The hierarchy delimiter of the target server, when known, for a root.
	 * @param bool $knownAbsent The caller has just looked the same path up and found nothing.
	 * @param string|null $sourcePath The folder of the old server, told to the observer before the CREATE.
	 * @param bool $createMissing Whether an absent folder may be created after the lookup.
	 * @return array{outcome: string, directory: ?array, created: bool, reason: ?string, error: mixed}
	 */
	public function ensure(
		Imap $client,
		?array $parent,
		string $segment,
		?string $rootDelimiter = null,
		bool $knownAbsent = false,
		?string $sourcePath = null,
		bool $createMissing = true,
	): array
	{
		$segment = FolderPairRules::canonicalSegment($segment);
		if ($segment === '' || strpbrk($segment, "\x00\r\n") !== false)
		{
			return self::notCreated(self::REASON_UNREPRESENTABLE);
		}

		$delimiter = $parent !== null ? (string)$parent['delim'] : (string)$rootDelimiter;
		$hasDelimiter = $delimiter !== '' && mb_strtoupper($delimiter) !== 'NIL';
		if ($parent !== null && !$hasDelimiter)
		{
			return self::notCreated(self::REASON_FLAT_NAMESPACE);
		}
		if ($hasDelimiter && str_contains($segment, $delimiter))
		{
			return self::notCreated(self::REASON_DELIMITER_IN_NAME);
		}

		$target = $parent !== null ? $parent['name'] . $delimiter . $segment : $segment;
		$isRoot = $parent === null;

		$found = $knownAbsent && $createMissing ? null : $this->lookup($client, $target, $isRoot, $error);
		if ($found === false)
		{
			return self::retry($error);
		}
		if ($found !== null)
		{
			return self::pair($found, false);
		}
		if (!$createMissing)
		{
			return self::notCreated(self::REASON_NOT_VISIBLE);
		}

		if ($sourcePath !== null && !$this->reportCreating($sourcePath, $target))
		{
			return self::notCreated(self::REASON_NOT_REMEMBERED);
		}

		$created = $client->withReadTimeout(
			$this->commandTimeout($client),
			static function () use ($client, $target, &$error, &$refusal): bool {
				return $client->createMailbox($target, $error, $refusal);
			},
		);
		if (!$created)
		{
			if ($refusal === null)
			{
				return self::createFailed('no_answer', $error);
			}

			$code = $refusal['code'];
			if ($code === null)
			{
				return self::notCreated(self::REASON_REFUSED_PREFIX . 'none');
			}
			if ($code !== 'ALREADYEXISTS')
			{
				$reason = self::REASON_REFUSED_PREFIX . $code;

				return in_array($code, self::FINAL_REFUSALS, true)
					? self::notCreated($reason)
					: self::createFailed($reason, $error);
			}
		}

		$found = $client->withReadTimeout(
			$this->commandTimeout($client),
			function () use ($client, $target, $isRoot, &$error): array|false|null {
				return $this->lookup($client, $target, $isRoot, $error);
			},
		);
		if ($found === false)
		{
			return self::retry($error);
		}

		return $found !== null ? self::pair($found, $created) : self::notCreated(self::REASON_NOT_VISIBLE);
	}

	private function commandTimeout(Imap $client): int
	{
		$timeout = $client->getReadTimeout();
		if ($this->deadline === null)
		{
			return $timeout;
		}

		return min($timeout, max(self::MINIMUM_COMMAND_TIMEOUT, $this->deadline - time()));
	}

	/**
	 * @return array|false|null The listed folder, false when the listing failed, null when it is absent.
	 *                          Exact names precede canonical ones and root-only case-insensitive names.
	 */
	private function lookup(Imap $client, string $target, bool $isRoot, mixed &$error): array|false|null
	{
		$listed = $client->listex('', $target, $error);
		if ($listed === false)
		{
			return false;
		}

		$matches = FolderPairRules::matchingDirectories($target, (array)$listed, $isRoot);

		return count($matches) === 1 ? reset($matches) : null;
	}

	private static function pair(array $directory, bool $created): array
	{
		return [
			'outcome' => self::OUTCOME_PAIR,
			'directory' => $directory,
			'created' => $created,
			'reason' => null,
			'error' => null,
		];
	}

	public static function notCreated(string $reason): array
	{
		return [
			'outcome' => self::OUTCOME_NOT_CREATED,
			'directory' => null,
			'created' => false,
			'reason' => $reason,
			'error' => null,
		];
	}

	private static function retry(mixed $error): array
	{
		return [
			'outcome' => self::OUTCOME_RETRY,
			'directory' => null,
			'created' => false,
			'reason' => null,
			'error' => $error,
		];
	}

	private static function createFailed(string $reason, mixed $error): array
	{
		return [
			'outcome' => self::OUTCOME_CREATE_FAILED,
			'directory' => null,
			'created' => false,
			'reason' => $reason,
			'error' => $error,
		];
	}
}
