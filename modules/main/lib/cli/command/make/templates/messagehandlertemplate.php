<?php

namespace Bitrix\Main\Cli\Command\Make\Templates;

use Bitrix\Main\Cli\Helper\Renderer\Template;

final class MessageHandlerTemplate implements Template
{
	public function __construct(
		private readonly string $handlerClassName,
		private readonly string $messageClassName,
		private readonly string $namespace,
	)
	{}

	public function getContent(): string
	{
		return <<<PHP
<?php

namespace {$this->namespace};

use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;

final class {$this->handlerClassName} extends AbstractReceiver
{
	protected function process(MessageInterface \$message): void
	{
		if ((\$message instanceof {$this->messageClassName}) === false)
		{
			throw new UnprocessableMessageException(\$message);
		}

		# process
	}
}
PHP;
	}
}
