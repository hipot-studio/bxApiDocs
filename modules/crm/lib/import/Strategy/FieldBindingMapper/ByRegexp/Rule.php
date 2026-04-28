<?php

namespace Bitrix\Crm\Import\Strategy\FieldBindingMapper\ByRegexp;

use Bitrix\Crm\Import\File\Header;
use Closure;

final class Rule
{
	private array $regexpList = [];

	private Closure $creator;

	public function __construct()
	{
		$this->creator = static fn () => null;
	}

	public function addRegexp(string $regexp): self
	{
		$this->regexpList[] = $regexp;

		return $this;
	}

	public function setRegexpList(array $regexpList): self
	{
		$this->regexpList = $regexpList;

		return $this;
	}

	public function setCreator(Closure $creator): self
	{
		$this->creator = $creator;

		return $this;
	}

	public function match(Header $header): ?string
	{
		foreach ($this->regexpList as $regexp)
		{
			unset($matches);
			if (preg_match($regexp, $header->getTitle(), $matches))
			{
				$fieldId = ($this->creator)($matches);
				if ($fieldId !== null)
				{
					return $fieldId;
				}
			}
		}

		return null;
	}
}
