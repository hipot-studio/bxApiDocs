<?php
namespace Bitrix\Landing\AI\SiteBuilder\Html;

final class AiMarkupNormalizer
{
	private array $allowedNodeTypes;

	public function __construct()
	{
		$this->allowedNodeTypes = AiMarkupRules::getDefaultNodeTypes();
	}

	public function normalizeNodeAttribute(string $value): string
	{
		$value = mb_strtolower(trim($value));

		return in_array($value, $this->allowedNodeTypes, true) ? $value : '';
	}

	public function normalizeHtml(string $html): string
	{
		$normalized = preg_replace_callback('/data-ai-node="([^"]+)"/i', function ($matches)
		{
			$value = $this->normalizeNodeAttribute((string)$matches[1]);

			return $value !== '' ? 'data-ai-node="' . $value . '"' : '';
		}, $html);
		$normalized = preg_replace('/\sdata-ai-style=("([^"]*)"|\'([^\']*)\')/i', '', (string)$normalized);

		$normalized = preg_replace('/\s+(\/?>)/', '$1', (string)$normalized);
		$normalized = preg_replace('/\s{2,}/', ' ', (string)$normalized);

		return trim((string)$normalized);
	}
}
