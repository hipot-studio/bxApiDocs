<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Entities;

use Bitrix\Anonymizer\Internal\Entities\Enum\RequestStatus;
use Bitrix\Anonymizer\Internal\Exceptions\AnonymizerException;
use Bitrix\Anonymizer\Internal\Integration\AnonymizerService\Command\CommandInterface;
use Bitrix\Anonymizer\Internal\Integration\AnonymizerService\ProxySender;
use Bitrix\Anonymizer\Internal\Repository\CommandRegistry;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Anonymizer\Internal\Models\RequestTable;
use Bitrix\Main\Error;
use Bitrix\Main\Security\Random;

/**
 * Request for execution: create with Quest, call execute($command). Load from DB via getByHash() or
 * getByQuestAndCommand() — returns a Request instance (internally LoadedRequest); consumers use Request only.
 */
class Request
{
	protected ?int $id = null;
	protected Quest $quest;
	protected ?CommandInterface $command;
	protected ?string $hash = null;

	protected ?array $result;
	protected ?Error $error;

	/**
	 * Find request by hash (e.g. from callback). Returns null if not found, command not in registry, or quest cannot
	 * be loaded.
	 */
	public static function getByHash(string $hash): ?self
	{
		return LoadedRequest::getByHash($hash);
	}

	/**
	 * Find request by quest id and command code. Returns null if not found, command not in registry, or quest cannot
	 * be loaded.
	 */
	public static function getByQuestAndCommand(int $questId, string $commandCode): ?self
	{
		return LoadedRequest::getByQuestAndCommand($questId, $commandCode);
	}

	public function __construct(Quest $quest)
	{
		$this->quest = $quest;
	}

	public function getCommand(): ?CommandInterface
	{
		return $this->command ?? null;
	}

	public function getQuest(): ?Quest
	{
		return $this->quest;
	}

	public function getResult(): ?array
	{
		return $this->result ?? null;
	}

	public function getError(): ?Error
	{
		return $this->error ?? null;
	}

	public function getStatus(): RequestStatus
	{
		if (isset($this->error))
		{
			return RequestStatus::Error;
		}

		if (isset($this->result))
		{
			return RequestStatus::Received;
		}

		if ($this->id !== null)
		{
			return RequestStatus::Sent;
		}

		return RequestStatus::New;
	}

	/**
	 * Executes the request once (idempotent). On first call saves request row and runs HTTP call.
	 * On repeated calls does nothing.
	 *
	 * @param CommandInterface $command
	 * @throws AnonymizerException
	 */
	public function execute(CommandInterface $command): void
	{
		// Guard against repeated execution for the same Request instance.
		if ($this->isSent())
		{
			return;
		}

		$this->executeViaProxy($command);
	}

	protected function executeViaProxy(CommandInterface $command): void
	{
		$this->command = $command;

		if (!$this->save())
		{
			return;
		}

		$callbackUri = $this->getProxyCallbackUri();
		$sender = new ProxySender();
		$result = $sender->callProxy($command, $callbackUri);

		if ($result['success'] && isset($result['hash']))
		{
			$this->setHash($result['hash']);

			return;
		}

		$this->setError($result['error'] ?? 'Proxy request failed');
		$this->onError();
	}

	protected function getProxyCallbackUri(): string
	{
		$uri = UrlManager::getInstance()->create(
			'anonymizer.api.Integration.proxyCallback',
			[],
			UrlManager::ABSOLUTE_URL,
		);

		return $uri->getUri();
	}

	protected function save(): bool
	{
		if ($this->isSent())
		{
			return true;
		}

		$questId = $this->quest->getId();
		$commandCode = $this->command::getCode();
		$existing = RequestTable::getRowByQuestAndCommand($questId, $commandCode);

		if ($existing !== null)
		{
			$this->id = (int)$existing['ID'];
			if (isset($existing['HASH']))
			{
				$this->hash = $existing['HASH'];
			}
			if (isset($existing['ERROR']))
			{
				$this->error = new Error($existing['ERROR']);
			}
			elseif (isset($existing['RESULT']) && is_array($existing['RESULT']))
			{
				$this->result = $existing['RESULT'];
			}

			return true;
		}

		$id = RequestTable::add([
			'COMMAND' => $commandCode,
			'QUEST_ID' => $questId,
			'RESULT' => $this->result ?? null,
			'ERROR' => isset($this->error) ? $this->error?->getMessage() : null,
		])->getId();

		if (!$id)
		{
			$this->error = new Error('Failed to save request');

			return false;
		}

		$this->id = (int)$id;

		return true;
	}

	protected function isSent(): bool
	{
		return isset($this->command, $this->id);
	}

	protected function setHash(string $hash): void
	{
		$this->hash = $hash;
		if ($this->id !== null)
		{
			RequestTable::update($this->id, ['HASH' => $hash]);
		}
	}

	public function setResult(array $result): self
	{
		$this->result = $result;
		if ($this->id !== null)
		{
			RequestTable::update($this->id, ['RESULT' => $result]);
		}

		return $this;
	}

	public function onResponse(): self
	{
		if (isset($this->result) && $this->isSent())
		{
			$context = $this->quest->getContext();
			$context->error = null;
			$command = $this->getCommand();
			$questId = $this->quest->getId();
			if ($command !== null && $questId !== null)
			{
				CommandRegistry::processResponse($command, $questId, $this->result);
			}
			$this->quest->getHandler()->onResponse($context);
		}

		return $this;
	}

	public function setError(string $error): self
	{
		$this->error = new Error($error);
		if ($this->id !== null)
		{
			RequestTable::update($this->id, ['ERROR' => $error]);
		}

		return $this;
	}

	public function onError(): self
	{
		if (isset($this->error) && $this->isSent())
		{
			$context = $this->quest->getContext();
			$this->getCommand()?->processError($context, $this->error->getMessage());
			$this->quest->getHandler()->onError($context);
		}

		return $this;
	}
}
