<?php

namespace Bitrix\Crm\Import\Dto\Entity;

use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\File\Header;
use Bitrix\Main\Engine\AutoWire\Binder;
use Closure;

final class HeaderFieldCreateRule
{
	private array $rules = [];
	private Closure $creator;
	private int $createCount = 0;

	public function __construct()
	{
		$this->creator = static fn () => null;
	}

	public function addRule(string $regexp): self
	{
		$this->rules[] = $regexp;

		return $this;
	}

	public function setRules(array $rules): self
	{
		$this->rules = $rules;

		return $this;
	}

	public function setCreator(Closure $creator): self
	{
		$this->creator = $creator;

		return $this;
	}

	public function match(Header $header): ?ImportEntityFieldInterface
	{
		foreach ($this->rules as $rule)
		{
			unset($matches);
			if (preg_match($rule, $header->getTitle(), $matches))
			{
				$binder = Binder::buildForFunction($this->creator)
					->setSourcesParametersToMap([
						[
							'matches' => $matches,
							'createCount' => $this->createCount + 1,
						],
					]);

				$field = $binder->invoke();
				if ($field instanceof ImportEntityFieldInterface)
				{
					$this->createCount++;

					return $field;
				}
			}
		}

		return null;
	}
}
