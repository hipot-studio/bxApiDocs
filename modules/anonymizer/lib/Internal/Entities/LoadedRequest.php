<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Entities;

use Bitrix\Anonymizer\Internal\Integration\AnonymizerService\Command\CommandInterface;
use Bitrix\Anonymizer\Internal\Repository\CommandRegistry;
use Bitrix\Anonymizer\Internal\Models\RequestTable;
use Bitrix\Main\Error;

/**
 * Request loaded from DB. Created only via Request::getByHash() or Request::getByQuestAndCommand() (which delegate here).
 * Consumers use Request; LoadedRequest extends Request and can be substituted.
 */
class LoadedRequest extends Request
{
	/**
	 * Constructor is private: use getByHash() only. Result and error are set via setRawResult/setRawError.
	 */
	private function __construct(
		int $id,
		Quest $quest,
		CommandInterface $command,
		?string $hash,
		?array $result,
		?string $error
	)
	{
		parent::__construct($quest);

		$this->id = $id;
		$this->command = $command;
		if (isset($hash))
		{
			$this->hash = $hash;
		}
		if (is_array($result))
		{
			$this->result = $result;
		}
		elseif (!empty($error))
		{
			$this->error = new Error($error);
		}
	}

	/**
	 * Find request by hash. Returns LoadedRequest or null if not found, required fields missing,
	 * command not in registry, or quest cannot be loaded via Quest::getById.
	 */
	public static function getByHash(string $hash): ?self
	{
		$row = RequestTable::query()
			->setSelect(['ID', 'HASH', 'COMMAND', 'QUEST_ID', 'RESULT', 'ERROR'])
			->where('HASH', $hash)
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		// todo: need command data to init
		$command = CommandRegistry::getByCode($row['COMMAND']);
		if ($command === null)
		{
			return null;
		}

		$quest = Quest::getById((int)$row['QUEST_ID']);
		if ($quest === null)
		{
			return null;
		}

		$id = (int)$row['ID'];
		$result = $row['RESULT'] ?? null;
		$error = $row['ERROR'] ?? null;

		return new self($id, $quest, $command, $hash, $result, $error);
	}

	/**
	 * Find request by quest id and command code. Returns LoadedRequest or null if not found,
	 * command not in registry, or quest cannot be loaded.
	 */
	public static function getByQuestAndCommand(int $questId, string $commandCode): ?self
	{
		$row = RequestTable::query()
			->setSelect(['ID', 'HASH', 'COMMAND', 'QUEST_ID', 'RESULT', 'ERROR'])
			->where('QUEST_ID', $questId)
			->where('COMMAND', $commandCode)
			->setLimit(1)
			->fetch();

		if (!$row || (int)($row['ID'] ?? 0) <= 0)
		{
			return null;
		}

		$command = CommandRegistry::getByCode($row['COMMAND']);
		if ($command === null)
		{
			return null;
		}

		$quest = Quest::getById($questId);
		if ($quest === null)
		{
			return null;
		}

		$id = (int)$row['ID'];

		$hash = $row['HASH'] ?? null;
		$result = isset($row['RESULT']) && is_array($row['RESULT']) ? $row['RESULT'] : null;
		$error = $row['ERROR'] ?? null;

		return new self($id, $quest, $command, $hash, $result, $error);
	}
}
