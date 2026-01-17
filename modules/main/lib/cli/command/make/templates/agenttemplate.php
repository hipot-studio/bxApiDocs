<?php

namespace Bitrix\Main\Cli\Command\Make\Templates;

use Bitrix\Main\Cli\Helper\Renderer\Template;

final class AgentTemplate implements Template
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
	public static function run(): ?string
	{
		\$instance = new static();
		\$result = \$instance->runInternal();

		if (\$result === false)
		{
			return null;
		}

		return __METHOD__ . '();';
	}

	private function runInternal(): bool
	{
		# process

		return true;
	}
}
PHP;
	}
}
