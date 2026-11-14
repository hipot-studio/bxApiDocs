<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Relation;

use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Public\Contract\Relation\RelationProviderInterface;
use Bitrix\Disk\Public\Event\OnBuildRelationProviderListEvent;
use Bitrix\Main\EventManager;
use Bitrix\Main\EventResult;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who answers Disk about the relations of which module.
 *
 * The list is put together by asking the modules themselves, once per instance and never between
 * requests: a list that outlived the request would outlive the installation, the switching off and the
 * update of a module, and a portal that has just been given a provider would keep serving pages without
 * it. The registry is declared as a service instead, so one request asks the modules once.
 *
 * Nothing a module gets wrong leaves this class. A class without the interface, a module id that
 * disagrees with the provider, a connector class two modules claim at once, a handler that throws - each
 * costs the module its own registration and leaves a line in the log, and the page is served with the
 * providers that are left. That is what the contract of the providers promises a reader: a module
 * without a provider publishes no entity types and no relations, rather than taking the page down with
 * it.
 */
final class RelationProviderRegistry
{
	private const LOGGER_CHANNEL = 'sync';

	/** @var array<string, RelationProviderInterface>|null moduleId to the provider of that module */
	private ?array $providersByModuleId = null;

	/** @var array<string, RelationProviderInterface> normalized connector class to the provider of it */
	private array $providersByConnectorClass = [];

	private LoggerInterface $logger;

	public function __construct(?LoggerInterface $logger = null)
	{
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'relation provider registry');
	}

	/**
	 * @return list<RelationProviderInterface>
	 */
	public function getProviders(): array
	{
		return array_values($this->build());
	}

	public function getProviderByModuleId(string $moduleId): ?RelationProviderInterface
	{
		return $this->build()[$moduleId] ?? null;
	}

	public function getProviderByConnectorClass(string $connectorClass): ?RelationProviderInterface
	{
		$this->build();

		return $this->providersByConnectorClass[self::normalizeConnectorClass($connectorClass)] ?? null;
	}

	/**
	 * @return array<string, RelationProviderInterface>
	 */
	private function build(): array
	{
		if ($this->providersByModuleId !== null)
		{
			return $this->providersByModuleId;
		}

		$providers = $this->askTheModules();
		$connectorClasses = [];

		foreach ($providers as $moduleId => $provider)
		{
			$declared = $this->declaredConnectorClasses($moduleId, $provider);
			if ($declared === null)
			{
				unset($providers[$moduleId]);

				continue;
			}

			$connectorClasses[$moduleId] = $declared;
		}

		foreach ($this->modulesClaimingOneConnectorClass($connectorClasses) as $moduleId)
		{
			unset($providers[$moduleId], $connectorClasses[$moduleId]);
		}

		$this->providersByModuleId = $providers;
		$this->providersByConnectorClass = [];
		foreach ($connectorClasses as $moduleId => $declared)
		{
			foreach (array_keys($declared) as $normalizedClass)
			{
				$this->providersByConnectorClass[$normalizedClass] = $providers[$moduleId];
			}
		}

		return $providers;
	}

	/**
	 * @return array<string, RelationProviderInterface>
	 */
	private function askTheModules(): array
	{
		$event = new OnBuildRelationProviderListEvent();
		$this->askTheHandlers($event);

		$providers = [];
		foreach ($event->getResults() as $result)
		{
			if ($result->getType() === EventResult::ERROR)
			{
				$this->logger->error(
					'a module answered the registration of providers of relations with an error',
					['moduleId' => (string)$result->getModuleId()],
				);

				continue;
			}

			foreach ($this->readRecords($result->getParameters()) as $record)
			{
				$registration = $this->readProvider($record);
				if ($registration === null)
				{
					continue;
				}

				[$moduleId, $provider] = $registration;
				if (isset($providers[$moduleId]))
				{
					// One provider answers for the whole of its module, so the second registration is either
					// a registration applied twice or two providers of one module - a configuration error
					// either way, and the one already registered is the one kept.
					$this->logger->error(
						'a second provider of relations of one module was dropped',
						['moduleId' => $moduleId, 'class' => $provider::class],
					);

					continue;
				}

				$providers[$moduleId] = $provider;
			}
		}

		return $providers;
	}

	/**
	 * The handlers are asked one by one instead of by Event::send(): the loop of the event manager carries
	 * no isolation of its own - an error of a handler, a failure of the loading of its module among them,
	 * breaks that loop off and the handlers sorted behind it are never asked at all. Asking the handlers
	 * found by the event manager one by one is how the registry of the types of user fields of the kernel
	 * is built as well {@see \Bitrix\Main\UserField\Internal\Registry::addFactoriesByEvent()}.
	 */
	private function askTheHandlers(OnBuildRelationProviderListEvent $event): void
	{
		$handlers = EventManager::getInstance()->findEventHandlers(
			$event->getModuleId(),
			$event->getEventType(),
			$event->getFilter(),
		);

		foreach ($handlers as $handler)
		{
			try
			{
				$this->askOneHandler($handler, $event);
			}
			catch (Throwable $error)
			{
				// A module that registered nothing says nothing to the log (4.1); this is the other case -
				// the registration was begun and broke off, and the module is left without a provider (4.2).
				$this->logger->error(
					'the registration of a provider of relations broke off: {message}',
					[
						'message' => $error->getMessage(),
						'moduleId' => (string)($handler['TO_MODULE_ID'] ?? ''),
						'handler' => (string)($handler['TO_NAME'] ?? ''),
						'exception' => $error,
					],
				);
			}
		}
	}

	/**
	 * One handler is called and answered for exactly the way the event manager does it: the event itself is
	 * the argument of a handler of the second version and the parameters of the event are the arguments of
	 * one of the first, while an answer that is not an EventResult is wrapped into one under the module
	 * that registered the handler {@see \Bitrix\Main\EventManager::sendToEventHandler()}.
	 *
	 * @param array<string, mixed> $handler a handler as the event manager keeps it
	 */
	private function askOneHandler(array $handler, OnBuildRelationProviderListEvent $event): void
	{
		$arguments = ($handler['VERSION'] ?? 1) > 1 ? [$event] : array_values($event->getParameters());

		$answer = ExecuteModuleEventEx($handler, $arguments);
		if (self::isNoAnswer($answer))
		{
			return;
		}

		$event->addResult(
			$answer instanceof EventResult
				? $answer
				: new EventResult(EventResult::UNDEFINED, $answer, $handler['TO_MODULE_ID'] ?? null),
		);
	}

	/**
	 * What the event manager counts as no answer at all: it compares the answer of a handler with null
	 * loosely, so false, zero, an empty string and an empty array are dropped and say nothing (4.1).
	 */
	private static function isNoAnswer(mixed $answer): bool
	{
		return !$answer && $answer !== '0';
	}

	/**
	 * One record is what the contract asks a handler for; a list of records is what the neighbouring
	 * registration of connectors of Disk answers with, and it is read as well.
	 *
	 * @return list<mixed>
	 */
	private function readRecords(mixed $parameters): array
	{
		if ($parameters === null)
		{
			return [];
		}

		if (!is_array($parameters))
		{
			$this->logger->error(
				'a module answered the registration of providers of relations with {type} instead of a record',
				['type' => get_debug_type($parameters)],
			);

			return [];
		}

		return array_is_list($parameters) ? $parameters : [$parameters];
	}

	/**
	 * @return array{string, RelationProviderInterface}|null the module of the record and the provider of it
	 */
	private function readProvider(mixed $record): ?array
	{
		if (!is_array($record))
		{
			$this->logger->error(
				'a record of a provider of relations is {type} instead of an array',
				['type' => get_debug_type($record)],
			);

			return null;
		}

		$moduleId = $record['MODULE_ID'] ?? null;
		$class = $record['CLASS'] ?? null;

		if (!is_string($moduleId) || $moduleId === '' || !is_string($class) || $class === '')
		{
			$this->logger->error(
				'a record of a provider of relations carries neither a module nor a class',
				['moduleId' => get_debug_type($moduleId), 'class' => get_debug_type($class)],
			);

			return null;
		}

		// Everything the record leads to is the code of the module: the autoloading of the class, the
		// building of it and the module it answers with all throw, and the one record is read as a whole so
		// that a throw of any of them costs that module the registration and nothing else.
		try
		{
			$provider = $this->buildProvider($moduleId, $class);
		}
		catch (Throwable $error)
		{
			$this->logger->error(
				'the provider of relations of a module could not be read: {message}',
				[
					'message' => $error->getMessage(),
					'moduleId' => $moduleId,
					'class' => $class,
					'exception' => $error,
				],
			);

			return null;
		}

		return $provider === null ? null : [$moduleId, $provider];
	}

	private function buildProvider(string $moduleId, string $class): ?RelationProviderInterface
	{
		if (!class_exists($class))
		{
			$this->logger->error(
				'the provider of relations of a module was not found',
				['moduleId' => $moduleId, 'class' => $class],
			);

			return null;
		}

		if (!is_subclass_of($class, RelationProviderInterface::class))
		{
			$this->logger->error(
				'the provider of relations of a module does not implement the contract',
				['moduleId' => $moduleId, 'class' => $class],
			);

			return null;
		}

		$provider = new $class();
		$answeredModuleId = $provider->getModuleId();

		if ($answeredModuleId !== $moduleId)
		{
			// Disk cannot tell which of the two names is the mistaken one, so it trusts neither.
			$this->logger->error(
				'the provider of relations was registered under a module of somebody else',
				[
					'moduleId' => $moduleId,
					'class' => $class,
					'answeredModuleId' => $answeredModuleId,
				],
			);

			return null;
		}

		return $provider;
	}

	/**
	 * @return array<string, string>|null normalized class to the spelling the provider declared it in,
	 *         null if the provider cannot even name the classes it answers for
	 */
	private function declaredConnectorClasses(string $moduleId, RelationProviderInterface $provider): ?array
	{
		try
		{
			$declared = $provider->getConnectorClasses();
		}
		catch (Throwable $error)
		{
			$this->logger->error(
				'the provider of relations could not name the connector classes it answers for: {message}',
				[
					'message' => $error->getMessage(),
					'moduleId' => $moduleId,
					'class' => $provider::class,
					'exception' => $error,
				],
			);

			return null;
		}

		$classes = [];
		foreach ($declared as $connectorClass)
		{
			if (!is_string($connectorClass))
			{
				continue;
			}

			$normalizedClass = self::normalizeConnectorClass($connectorClass);
			if ($normalizedClass !== '')
			{
				$classes[$normalizedClass] ??= $connectorClass;
			}
		}

		return $classes;
	}

	/**
	 * A connector class is served by exactly one provider: two of them claiming one class is a
	 * configuration error, and both are dropped rather than resolved by the order they registered in.
	 *
	 * @param array<string, array<string, string>> $connectorClasses
	 * @return list<string> the modules to drop
	 */
	private function modulesClaimingOneConnectorClass(array $connectorClasses): array
	{
		$claims = [];
		$asDeclared = [];
		foreach ($connectorClasses as $moduleId => $declared)
		{
			foreach ($declared as $normalizedClass => $connectorClass)
			{
				$claims[$normalizedClass][] = $moduleId;
				$asDeclared[$normalizedClass] ??= $connectorClass;
			}
		}

		$clashing = [];
		foreach ($claims as $normalizedClass => $moduleIds)
		{
			if (count($moduleIds) < 2)
			{
				continue;
			}

			$this->logger->error(
				'one connector class is claimed by the providers of relations of several modules',
				[
					'connectorClass' => $asDeclared[$normalizedClass],
					'moduleIds' => implode(', ', $moduleIds),
				],
			);

			foreach ($moduleIds as $moduleId)
			{
				$clashing[$moduleId] = true;
			}
		}

		return array_keys($clashing);
	}

	/**
	 * One class reaches Disk written in more than one way: ENTITY_TYPE of b_disk_attached_object keeps the
	 * doubled backslashes the row was written with (@see \Bitrix\Disk\Uf\Connector::buildFromAttachedObject()),
	 * the leading backslash is optional and PHP knows no case in the name of a class. All of them name one
	 * provider, or a consumer asking by the row of the table finds none and the attachment passes as trusted.
	 */
	private static function normalizeConnectorClass(string $connectorClass): string
	{
		return strtolower(ltrim(str_replace('\\\\', '\\', $connectorClass), '\\'));
	}
}
