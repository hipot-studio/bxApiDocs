<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step\Helper;

final class ChangeAiSiteDomHelper
{
	public static function extractRootHtml(object $root): string
	{
		$html = '';
		foreach ($root->getChildNodesArray() as $child)
		{
			if (method_exists($child, 'getOuterHTML'))
			{
				$html .= $child->getOuterHTML();
			}
		}

		return trim($html);
	}
}
