<?php
namespace Bitrix\Landing\Copilot\Services\Manifest;

use Bitrix\Landing\Copilot\Services\HtmlBlock\NodeTagger;
use Bitrix\Landing\Error;
use Bitrix\Main\Web\DOM\Document;

class Builder
{
	private const DEFAULT_NODE_TYPES = [
		'text',
		'link',
		'img',
		'icon',
	];
	private const FALLBACK_HTML = '<section></section>';

	private Error $error;
	private ?string $sanitizedHtml = null;
	private array $nodeTypes;

	public function __construct(array $nodeTypes = [])
	{
		$this->error = new Error();
		$this->nodeTypes = $this->normalizeTypes($nodeTypes, self::DEFAULT_NODE_TYPES);
	}

	public function getError(): Error
	{
		return $this->error;
	}

	public function getSanitizedHtml(): ?string
	{
		return $this->sanitizedHtml;
	}

	public function setNodeTypes(array $nodeTypes): void
	{
		$this->nodeTypes = $this->normalizeTypes($nodeTypes, self::DEFAULT_NODE_TYPES);
	}

	public function build(string $html, array $meta): array
	{
		$this->error = new Error();
		$this->sanitizedHtml = $this->prepareHtml($html);
		$doc = $this->loadDocument($this->sanitizedHtml);
		$this->removeStyleAttributes($doc);

		$collector = new NodeCollector($this->nodeTypes);
		$collection = $collector->collectNodes(NodeCollector::findNodeElements($doc));
		$cards = $collector->buildCards(
			$collector->collectCardCodes($doc),
			$collection['cardLabels']
		);
		$preparedHtml = $this->extractRootHtml($doc);
		if ($preparedHtml !== '')
		{
			$this->sanitizedHtml = $preparedHtml;
		}

		return $this->buildManifest($meta, $collection['nodes'], $cards);
	}

	private function normalizeTypes(array $types, array $fallback): array
	{
		$items = array_map(static fn($value) => mb_strtolower(trim((string)$value)), $types);
		$items = array_values(array_filter($items, static fn($value) => $value !== ''));
		if (!$items)
		{
			return $fallback;
		}

		return array_values(array_unique($items));
	}

	private function loadDocument(string $html): Document
	{
		$doc = new Document();

		try
		{
			$doc->loadHTML($html);
		}
		catch (\Throwable)
		{
			$doc->loadHTML(self::FALLBACK_HTML);
		}

		return $doc;
	}

	private function normalizeSections($sections): array
	{
		if (!is_array($sections))
		{
			$sections = [$sections];
		}

		return array_values(array_filter($sections, fn($value) => (string)$value !== ''));
	}

	private function prepareHtml(string $html): string
	{
		$html = trim($html);

		return $html !== '' ? $html : self::FALLBACK_HTML;
	}

	private function extractRootHtml(Document $doc): string
	{
		return trim($doc->saveHTML());
	}

	private function removeStyleAttributes(Document $doc): void
	{
		foreach ($doc->querySelectorAll('[data-ai-style]') as $element)
		{
			$element->removeAttribute('data-ai-style');
		}
	}

	private function buildManifest(array $meta, array $nodes, array $cards): array
	{
		$sections = $this->normalizeSections($meta['sections'] ?? ['ai']);

		return [
			'block' => [
				'name' => (string)($meta['name'] ?? ''),
				'section' => $sections ?: ['ai'],
				'type' => ['ai'],
			],
			'nodes' => $nodes,
			'cards' => $cards,
			'style' => [
				'block' => [],
				'nodes' => [],
			],
			'taggerVersion' => NodeTagger::VERSION,
		];
	}
}
