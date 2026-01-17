<?php

namespace Bitrix\Main\Cli\Command\Make\Templates;

use Bitrix\Main\Cli\Helper\Renderer\Template;

final class MessageTemplate implements Template
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

use Bitrix\Main\Messenger\Entity\AbstractMessage;

final class {$this->className} extends AbstractMessage
{
	public function __construct(
		public readonly string \$param1,
	)
	{}
}
PHP;
	}
}
