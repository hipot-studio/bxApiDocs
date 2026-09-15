<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Entities;

use Bitrix\Anonymizer\Internal\Entities\Enum\RequestStatus;
use Bitrix\Anonymizer\Internal\Exceptions\AnonymizerException;
use Bitrix\Anonymizer\Internal\Integration\AnonymizerService\Command\CommandInterface;
use Bitrix\Anonymizer\Internal\Models\QuestTable;
use Bitrix\Anonymizer\Internal\Repository\CommandRegistry;
use Bitrix\Anonymizer\Internal\Repository\RequestRegistry;
use Bitrix\Anonymizer\Public\Context\QuestContext;
use Bitrix\Anonymizer\Public\Handlers\QuestHandler;
use Bitrix\Anonymizer\Public\Handlers\QuestHandlerInterface;
use Bitrix\Anonymizer\Public\Providers\ProviderInterface;
use Bitrix\Anonymizer\Public\Resolvers\ResolverInterface;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

/**
 * Quest for the "start" flow: create with provider, handler, module (saved to DB in constructor); get questId; call
 * anonymize() or other command methods to run request. Load from DB via getById() — returns a Quest instance
 * (internally LoadedQuest); consumers use Quest only.
 *
 * @template TProvider of ProviderInterface
 */
class Quest
{
	/** When true, quest is saved to DB in constructor so getId() is available immediately. Override to false in LoadedQuest. */
	protected const SAVE_IN_CONSTRUCTOR = true;

	protected ?int $id = null;

	/** @var TProvider */
	protected ProviderInterface $provider;
	protected string $handlerClass;
	protected ?QuestHandlerInterface $handler;
	protected string $forModule;

	/** @var QuestContext<TProvider> */
	protected QuestContext $context;

	/**
	 * Create a new quest for the start flow. Use new Quest(...). Quest is saved to DB in constructor so getId() is
	 * available immediately.
	 *
	 * @param TProvider $provider Provider that supplies data for anonymization.
	 * @param string $handlerClass Handler class that processes anonymization events.
	 * @param string $forModule Calling module.
	 * @throws AnonymizerException
	 */
	public function __construct(ProviderInterface $provider, string $handlerClass, string $forModule)
	{
		try
		{
			$this->forModule = $forModule;
			Loader::includeModule($this->forModule);
		}
		catch (LoaderException $e)
		{
			throw new AnonymizerException('Can\'t load module for handle response');
		}

		$this->provider = $provider;

		$this->handlerClass = $handlerClass;
		if (!class_exists($this->handlerClass) || !is_subclass_of($this->handlerClass, QuestHandler::class))
		{
			throw new AnonymizerException('Handler class is not a subclass of QuestHandler');
		}

		$this->context = new QuestContext(0, $this->provider);

		if (static::SAVE_IN_CONSTRUCTOR && !$this->save())
		{
			throw new AnonymizerException('Failed to save quest');
		}
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	/**
	 * Load quest from DB by ID. Provider type is not known statically (restored from PROVIDER_CLASS).
	 *
	 * @return Quest<ProviderInterface>|null
	 */
	public static function getById(int $id): ?self
	{
		return LoadedQuest::getById($id);
	}

	/**
	 * @return QuestContext<TProvider>
	 */
	public function getContext(): QuestContext
	{
		return $this->context;
	}

	public function getHandler(): QuestHandlerInterface
	{
		if (!isset($this->handler))
		{
			$this->handler = new $this->handlerClass();
		}

		return $this->handler;
	}

	/**
	 * Runs anonymize command for the current provider
	 * @throws AnonymizerException
	 */
	public function anonymize(): self
	{
		$command = CommandRegistry::getForAnonymize($this->provider);
		$this->runCommand($command);

		return $this;
	}

	public function isAnonymizeSuccess(): bool
	{
		$request = $this->getAnonymizeRequest();

		return $request !== null
			&& $request->getStatus() === RequestStatus::Received
			&& $request->getResult() !== null
		;
	}

	public function isAnonymizePending(): bool
	{
		$request = $this->getAnonymizeRequest();
		if ($request === null)
		{
			return false;
		}

		$status = $request->getStatus();

		return $status === RequestStatus::Sent || $status === RequestStatus::New;
	}

	public function isAnonymizeError(): bool
	{
		$request = $this->getAnonymizeRequest();

		return $request !== null && $request->getStatus() === RequestStatus::Error;
	}

	public function getAnonymizeError(): ?string
	{
		if (!$this->isAnonymizeError())
		{
			return null;
		}

		return $this->getAnonymizeRequest()?->getError()?->getMessage();
	}

	/**
	 * Creates resolver for the given class (with quest provider), fills it from replacements saved on onResponse.
	 *
	 * @template T of ResolverInterface
	 * @param class-string<T> $resolverClass
	 * @return T|null
	 */
	public function withAnonymizeResponse(string $resolverClass): ?ResolverInterface
	{
		if (!$this->isAnonymizeSuccess())
		{
			return null;
		}

		try
		{
			$resolver = new $resolverClass($this->provider);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!$resolver instanceof ResolverInterface)
		{
			return null;
		}

		try
		{
			CommandRegistry::fillResolverForAnonymize($this, $resolver);
		}
		catch (AnonymizerException)
		{
			return null;
		}

		return $resolver;
	}

	protected function getAnonymizeRequest(): ?Request
	{
		$questId = $this->id;
		if ($questId === null)
		{
			return null;
		}

		$commandCode = CommandRegistry::resolveAnonymizeCommandCode($this->provider);
		if ($commandCode === null)
		{
			return null;
		}

		return Request::getByQuestAndCommand($questId, $commandCode);
	}

	/**
	 * Runs command: one request per command (created or loaded on the fly). If request was already
	 * sent, does not send again; if result/error already received, delivers to handler.
	 *
	 * @throws AnonymizerException
	 */
	protected function runCommand(CommandInterface $command): void
	{
		$request = RequestRegistry::getForCommand($this, $command);
		$request->execute($command);

		$status = $request->getStatus();
		if ($status === RequestStatus::Error)
		{
			$request->onError();
		}
		elseif ($status === RequestStatus::Received)
		{
			$request->onResponse();
		}
	}

	protected function save(): bool
	{
		$providerData = $this->provider->getData() ?? [];
		$data = [
			'PROVIDER_CLASS' => $this->provider::class,
			'PROVIDER_DATA' => $providerData,
			'HANDLER_CLASS' => $this->handlerClass,
			'MODULE_ID' => $this->forModule,
		];

		if ($this->id !== null)
		{
			$res = QuestTable::update($this->id, $data);
		}
		else
		{
			$res = QuestTable::add($data);
			if ($res->isSuccess())
			{
				$this->id = $res->getId();
				$this->context->questId = $this->id;
			}
		}

		return $res->isSuccess();
	}
}
