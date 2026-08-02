<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

use Bitrix\Main\UpdateSystem\Migration\Tools\SourceCode;

class Event {
	private readonly string $eventManagerClass;

	public function __construct(
		private readonly Context $context,
		?string $eventManagerClass = null,
	)
	{
		$this->eventManagerClass = $eventManagerClass ?? \Bitrix\Main\EventManager::class;
	}

	/**
	 * @param string|array{class-string, string} $toClass Class FQCN (with $toMethod) or `[Class::class, 'method']` tuple.
	 * @param string|null $toMethod Required when $toClass is a string, ignored when $toClass is a tuple.
	 * @param int|null $sort Handler invocation order; null keeps the default (100).
	 */
	public function register(string $fromModuleId, string $eventType, string|array $toClass, ?string $toMethod = null, ?int $sort = null): self
	{
		[$toClass, $toMethod] = $this->normalizeHandler($toClass, $toMethod);

		return $this->doRegister(false, $fromModuleId, $eventType, $toClass, $toMethod, $sort);
	}

	/**
	 * @param string|array{class-string, string} $toClass
	 * @param int|null $sort Handler invocation order; null keeps the default (100).
	 */
	public function registerCompatible(string $fromModuleId, string $eventType, string|array $toClass, ?string $toMethod = null, ?int $sort = null): self
	{
		[$toClass, $toMethod] = $this->normalizeHandler($toClass, $toMethod);

		return $this->doRegister(true, $fromModuleId, $eventType, $toClass, $toMethod, $sort);
	}

	private function doRegister(
		bool $compatible,
		string $fromModuleId,
		string $eventType,
		string $toClass,
		string $toMethod,
		?int $sort,
	): self
	{
		$mode = $this->context->getDatabaseUpdateMode();
		if ($mode === DatabaseUpdateMode::ModuleUninstall)
		{
			return $this->unregister($fromModuleId, $eventType, $toClass, $toMethod);
		}

		if (
			$this->context->isDevMode()
			&& SourceCode::classBelongsToModule($toClass, $this->context->getModuleId()) === false
		)
		{
			throw new Exception(
				$this->context->getModuleId(),
				300,
				'Event handler should belong to module',
			);
		}

		// ModuleInstall bypasses the `isModuleInstalled()` guard because the module
		// registry entry is not yet written when install migrations run.
		$moduleInstalled = $mode->isModuleInstall() || $this->context->isModuleInstalled();
		if (!$mode->canUpdateDatabase() || !$moduleInstalled)
		{
			return $this;
		}

		$eventManager = $this->eventManagerClass;
		if ($compatible)
		{
			$eventManager::getInstance()->registerEventHandlerCompatible(
				$fromModuleId,
				$eventType,
				$this->context->getModuleId(),
				$toClass,
				$toMethod,
				$sort ?? 100,
			);
		}
		else
		{
			$eventManager::getInstance()->registerEventHandler(
				$fromModuleId,
				$eventType,
				$this->context->getModuleId(),
				$toClass,
				$toMethod,
				$sort ?? 100,
			);
		}

		return $this;
	}

	/**
	 * @param string|array{class-string, string} $toClass
	 */
	public function unregister(string $fromModuleId, string $eventType, string|array $toClass, ?string $toMethod = null): self
	{
		[$toClass, $toMethod] = $this->normalizeHandler($toClass, $toMethod);

		if (
			$this->context->isDevMode()
			&& SourceCode::classBelongsToModule($toClass, $this->context->getModuleId()) === false
		)
		{
			throw new Exception(
				$this->context->getModuleId(),
				300,
				'Event handler should belong to module',
			);
		}

		$mode = $this->context->getDatabaseUpdateMode();
		// Module install never unregisters — there is nothing wired up yet.
		if ($mode->isModuleInstall())
		{
			return $this;
		}
		if ($mode->canUpdateDatabase() || $mode === DatabaseUpdateMode::ModuleUninstall)
		{
			$eventManager = $this->eventManagerClass;
			$eventManager::getInstance()->unRegisterEventHandler(
				$fromModuleId,
				$eventType,
				$this->context->getModuleId(),
				$toClass,
				$toMethod,
			);
		}

		return $this;
	}

	/**
	 * @param string|array{class-string, string} $toClass
	 * @return array{0: string, 1: string}
	 */
	private function normalizeHandler(string|array $toClass, ?string $toMethod): array
	{
		if (is_array($toClass))
		{
			[$class, $method] = $toClass;

			return [$class, $method];
		}

		if ($toMethod === null)
		{
			throw new Exception(
				$this->context->getModuleId(),
				301,
				'Wrong event handler arguments format',
			);
		}

		return [$toClass, $toMethod];
	}
}
