<?php

namespace Bitrix\Main\Cli\Command\Make\Templates;

use Bitrix\Main\Cli\Helper\Renderer\Template;

final class EntityTemplate implements Template
{
	public function __construct(
		private readonly array $fields,
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
{$this->generateProperties()}
}
PHP;
	}

	private function generateProperties(): string
	{
		if (empty($this->fields))
		{
			return '';
		}

		$result = '';
		foreach ($this->fields as $fieldName)
		{
			$result .= "\tprivate ?string \${$fieldName};\n";
		}

		return $result;
	}
}
