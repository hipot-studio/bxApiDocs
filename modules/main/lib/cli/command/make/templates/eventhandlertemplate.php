<?php

namespace Bitrix\Main\Cli\Command\Make\Templates;

use Bitrix\Main\Cli\Helper\Renderer\Template;

final class EventHandlerTemplate implements Template
{
	public function __construct(
		private readonly string $handlerClassName,
		private readonly string $eventClassName,
		private readonly string $namespace,
	)
	{}

	public function getContent(): string
	{
		return <<<PHP
<?php

namespace {$this->namespace};

use Bitrix\Main\EventResult;

final class {$this->handlerClassName}
{
	public static function handle({$this->eventClassName} \$event): EventResult
	{
		# process

		return new EventResult(EventResult::SUCCESS);
	}
}
PHP;
	}
}
