<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

final class ReportTextHtmlRenderer
{
	public function render(string $text): string
	{
		if ($text === '')
		{
			return '';
		}

		$text = preg_replace('/\[br\s*\/?]/i', "\n", $text) ?? $text;

		$parser = new \CTextParser();
		$parser->useTypography = false;
		$parser->allow['P'] = 'Y';
		$parser->allow['SMILES'] = 'N';
		$parser->allow['NL2BR'] = 'Y';
		$parser->allow['USER'] = 'N';
		$parser->allow['PROJECT'] = 'N';
		$parser->allow['DEPARTMENT'] = 'N';

		return $parser->convertText($text);
	}
}
