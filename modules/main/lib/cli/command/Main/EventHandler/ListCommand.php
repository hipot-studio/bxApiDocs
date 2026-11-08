<?php

namespace Bitrix\Main\Cli\Command\Main\EventHandler;

use Bitrix\Main\Cli\Command\AbstractFormattedCommand;
use Bitrix\Main\Cli\Output\OutputFormatter;
use Bitrix\Main\EventManager;
use Closure;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends AbstractFormattedCommand
{
	private const SCOPE = 'current_cli_process';

	public function __construct(
		private readonly EventManager $eventManager,
		OutputFormatter $outputFormatter,
	)
	{
		parent::__construct($outputFormatter);
	}

	protected function configure(): void
	{
		$this
			->setName('main:event-handler:list')
			->setDescription('List event handlers available in current CLI process')
			->addArgument(
				'module',
				InputArgument::REQUIRED,
				'Event module identifier',
			)
			->addArgument(
				'event',
				InputArgument::REQUIRED,
				'Event name',
			)
		;
	}

	protected function executeFormatted(InputInterface $input, OutputInterface $output): int
	{
		try
		{
			$handlers = $this->getHandlers(
				(string)$input->getArgument('module'),
				(string)$input->getArgument('event'),
			);
		}
		catch (InvalidArgumentException $exception)
		{
			return $this->renderError($output, 'INVALID_ARGUMENT', $exception->getMessage(), self::INVALID);
		}

		return $this->renderSuccess(
			$output,
			$handlers,
			['scope' => self::SCOPE],
			[
				'moduleId' => 'Module',
				'event' => 'Event',
				'handlerModuleId' => 'Handler module',
				'class' => 'Class',
				'method' => 'Method',
				'function' => 'Function',
				'sort' => 'Sort',
				'source' => 'Source',
				'scope' => 'Scope',
			],
		);
	}

	private function getHandlers(string $moduleId, string $event): array
	{
		$moduleId = trim($moduleId);
		$event = trim($event);
		if ($moduleId === '')
		{
			throw new InvalidArgumentException(
				'Specify module identifier',
			);
		}
		if ($event === '')
		{
			throw new InvalidArgumentException(
				'Specify event name',
			);
		}

		$handlers = [];
		$order = 0;
		foreach ($this->findHandlers($moduleId, $event) as $handler)
		{
			[$class, $method, $function] = $this->describeCallable($handler);
			$handlers[] = [
				'moduleId' => $moduleId,
				'event' => $event,
				'handlerModuleId' => $this->nullableString($handler['TO_MODULE_ID'] ?? null),
				'class' => $class,
				'method' => $method,
				'function' => $function,
				'sort' => (int)($handler['SORT'] ?? 100),
				'source' => !empty($handler['FROM_DB']) ? 'database' : 'runtime',
				'scope' => self::SCOPE,
				'_order' => $order++,
			];
		}
		usort(
			$handlers,
			static fn(array $left, array $right): int => [$left['sort'], $left['_order']]
				<=> [$right['sort'], $right['_order']],
		);
		foreach ($handlers as &$handler)
		{
			unset($handler['_order']);
		}
		unset($handler);

		return $handlers;
	}

	protected function findHandlers(string $moduleId, string $event): array
	{
		return $this->eventManager->findEventHandlers($moduleId, $event);
	}

	private function describeCallable(array $handler): array
	{
		$class = $this->nullableString($handler['TO_CLASS'] ?? null);
		$method = $this->nullableString($handler['TO_METHOD'] ?? null);
		$function = null;
		if ($class === null && $method !== null)
		{
			[$method, $function] = [null, $method];
		}
		$callback = $handler['CALLBACK'] ?? null;
		if (is_array($callback) && isset($callback[0], $callback[1]))
		{
			$owner = $callback[0];
			$class = is_string($owner) ? $owner : (is_object($owner) && !$owner instanceof Closure ? get_class($owner) : null);
			$method = is_string($callback[1]) ? $callback[1] : null;
			$function = null;
		}
		elseif (is_string($callback))
		{
			if (str_contains($callback, '::'))
			{
				[$class, $method] = explode('::', $callback, 2);
				$function = null;
			}
			else
			{
				[$class, $method, $function] = [null, null, $callback];
			}
		}
		elseif (is_object($callback) && !$callback instanceof Closure)
		{
			[$class, $method, $function] = [get_class($callback), '__invoke', null];
		}

		return [$this->nullableString($class), $this->nullableString($method), $this->nullableString($function)];
	}

	private function nullableString(mixed $value): ?string
	{
		return is_string($value) && $value !== '' ? $value : null;
	}
}
