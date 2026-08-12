<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Services\Manifest;

/**
 * Merge rules for the repo row manifest: block metadata is merged, markup shape
 * (nodes, cards, style) is replaced by the rebuilt manifest, assets follow the html,
 * foreign top level keys of the current manifest are preserved.
 */
final class RepoManifestMerger
{
	public function merge(array $currentManifest, array $rebuiltManifest, string $generatedHtml): array
	{
		$nextManifest = $currentManifest;
		$nextManifest['block'] = array_merge(
			is_array($currentManifest['block'] ?? null) ? $currentManifest['block'] : [],
			is_array($rebuiltManifest['block'] ?? null) ? $rebuiltManifest['block'] : [],
		);
		$nextManifest['nodes'] = is_array($rebuiltManifest['nodes'] ?? null) ? $rebuiltManifest['nodes'] : [];
		$nextManifest['cards'] = is_array($rebuiltManifest['cards'] ?? null) ? $rebuiltManifest['cards'] : [];
		$nextManifest['style'] = is_array($rebuiltManifest['style'] ?? null)
			? $rebuiltManifest['style']
			: ['block' => [], 'nodes' => []]
		;
		$nextManifest['assets'] = $this->buildAssets($currentManifest, $generatedHtml);
		if (isset($rebuiltManifest['taggerVersion']))
		{
			$nextManifest['taggerVersion'] = (int)$rebuiltManifest['taggerVersion'];
		}

		return $nextManifest;
	}

	private function buildAssets(array $currentManifest, string $generatedHtml): array
	{
		$assets = is_array($currentManifest['assets'] ?? null) ? $currentManifest['assets'] : [];
		$ext = $assets['ext'] ?? [];
		$ext = is_array($ext) ? $ext : [$ext];
		$ext = array_values(array_filter(array_map(static fn($value) => trim((string)$value), $ext)));

		$hasFormEmbed = str_contains($generatedHtml, 'bitrix24forms') || str_contains($generatedHtml, 'data-b24form');
		if ($hasFormEmbed)
		{
			if (!in_array('landing_form', $ext, true))
			{
				$ext[] = 'landing_form';
			}
		}
		else
		{
			$ext = array_values(array_filter($ext, static fn($value) => $value !== 'landing_form'));
		}

		if ($ext === [])
		{
			unset($assets['ext']);
		}
		else
		{
			$assets['ext'] = $ext;
		}

		return $assets;
	}
}
