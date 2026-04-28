<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\Starter;

class Result extends \Bitrix\Main\Result
{
	public function getConversionResult(): ?\Bitrix\Crm\Automation\Converter\Result
	{
		return $this->data['conversionResult'] ?? null;
	}

	public function setConversionResult(\Bitrix\Crm\Automation\Converter\Result $conversionResult): static
	{
		$this->data['conversionResult'] = $conversionResult;

		return $this;
	}
}
