<?php

namespace Bitrix\Main\Cli\Command\Make\Templates;

use Bitrix\Main\Cli\Helper\Renderer\Template;

final class RequestTemplate implements Template
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
	public function __construct(
{$this->generateConstructArguments()}
	)
	{}

	public static function createFromRequest(\Bitrix\Main\Request \$request): self
	{
		return new self(
{$this->generateConstructValues()}
		);
	}
}
PHP;
	}

	private function generateConstructArguments(): string
	{
		if (empty($this->fields))
		{
			return '';
		}

		$result = '';
		foreach ($this->fields as $fieldName)
		{
			$result .= "\t\tpublic readonly ?string \${$fieldName},\n";
		}

		return $result;
	}

	private function generateConstructValues(): string
	{
		if (empty($this->fields))
		{
			return '';
		}

		$result = '';
		foreach ($this->fields as $fieldName)
		{
			$result .= "\t\t\t\$request->get('{$fieldName}'),\n";
		}

		return $result;
	}
}
