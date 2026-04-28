<?php

namespace Bitrix\Crm\Import\ImportEntityFields\Trait;

use Bitrix\Crm\Import\Enum\NameFormat;

trait CanConfigureNameFormatTrait
{
	protected NameFormat $nameFormat = NameFormat::Default;

	public function configureNameFormat(NameFormat $nameFormat): self
	{
		$this->nameFormat = $nameFormat;

		return $this;
	}
}
