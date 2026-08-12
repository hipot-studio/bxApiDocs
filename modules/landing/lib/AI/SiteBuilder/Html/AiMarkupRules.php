<?php
namespace Bitrix\Landing\AI\SiteBuilder\Html;

final class AiMarkupRules
{
	private const DEFAULT_NODE_TYPES = [
		'text',
		'link',
		'img',
		'icon',
	];

	public static function getDefaultNodeTypes(): array
	{
		return self::DEFAULT_NODE_TYPES;
	}
}
