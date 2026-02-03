<?php

namespace Bitrix\Mail\Integration\HumanResources;

use Bitrix\Main\Loader;

class StructureNode
{
	public static function getUrlToFocusNode(int $focusNodeId): ?string
	{
		$isHumanResourcesAvailable = Loader::includeModule('humanresources');
		$structureFocusNodeLink = '/hr/structure?focusNodeId=%u';

		return $isHumanResourcesAvailable
			? sprintf($structureFocusNodeLink, $focusNodeId)
			: null
		;
	}
}