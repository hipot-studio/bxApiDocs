<?php

namespace Bitrix\Main\Cli\Command\Make\Templates;

use Bitrix\Main\Cli\Helper\Renderer\Template;

final class ServiceTemplate implements Template
{
	public function __construct(
		private readonly string $className,
		private readonly string $namespace,
	)
	{}

	public function getContent(): string
	{
		return <<<PHP
<?php

namespace {$this->namespace};

final class {$this->className}
{
	public function __construct(
		# dependencies
	)
	{}
}
PHP;
	}
}
