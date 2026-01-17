<?php

namespace Bitrix\Main\Cli\Command\Make\Templates;

use Bitrix\Main\Cli\Helper\Renderer\Template;

final class EventTemplate implements Template
{
	public function __construct(
		private readonly string $moduleId,
		private readonly string $eventName,
		private readonly string $className,
		private readonly string $namespace,
	)
	{}

	public function getContent(): string
	{
		return <<<PHP
<?php

namespace {$this->namespace};

use Bitrix\Main\Event;

final class {$this->className} extends Event
{
	public function __construct(
		public readonly string \$param1,
	)
	{
		parent::__construct(
			'{$this->moduleId}',
			'{$this->eventName}',
		);
	}
}
PHP;
	}
}
