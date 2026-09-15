<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Entities\Replacement;

class ReplacementDto
{
	public string $code;
	public string $text;
	public string $label;
	public int $start;
	public int $end;

	public function getReplace(): string
	{
		$code = $this->code ?? '';

		return "[$code]";
	}

	public static function entityKey(self $replacement): string
	{
		return $replacement->start . ':' . $replacement->end . ':' . $replacement->label;
	}
}