<?php

namespace Bitrix\Landing\Markup\Image;

use Bitrix\Landing\Markup\Manifest;
use Bitrix\Landing\Markup\Package;

/**
 * Plan of the images of a submitted package against the current version of the block.
 *
 * A generate declaration with the same token, the same trimmed prompt and the same aspect as in
 * the current version inherits its outcome: a generated picture keeps its file (reuse), a failed
 * one stays a placeholder (keep_failed), the model is not asked. A new token, another prompt or
 * aspect is a generation; the one-off regenerate flag of a declaration forces it, including the
 * only retry of a failed picture. An empty prompt has nothing to generate from. An external image
 * is a download. The outcome a submission carries is never read: the current version tells.
 */
class ImagePlan
{
	public const ACTION_GENERATE = 'generate';
	public const ACTION_REUSE = 'reuse';
	public const ACTION_KEEP_FAILED = 'keep_failed';
	public const ACTION_DOWNLOAD = 'download';

	/** @var list<array{token: string, action: string, prompt: string, aspect: string}> */
	private array $items = [];

	/**
	 * @param array<int, array> $declarations Image declarations of the submitted package.
	 * @param Manifest|null $current Manifest of the current version; null for the first one.
	 */
	public static function build(array $declarations, ?Manifest $current): self
	{
		$plan = new self();
		foreach ($declarations as $declaration)
		{
			if (!is_array($declaration) || (string)($declaration['token'] ?? '') === '')
			{
				continue;
			}

			$token = (string)$declaration['token'];
			$prompt = trim((string)($declaration['prompt'] ?? ''));
			$aspect = (string)($declaration['aspect'] ?? '');
			$plan->items[] = [
				'token' => $token,
				'action' => self::resolveAction($declaration, $token, $prompt, $aspect, $current),
				'prompt' => $prompt,
				'aspect' => $aspect,
			];
		}

		return $plan;
	}

	/**
	 * @return list<array{token: string, action: string, prompt: string, aspect: string}>
	 */
	public function getItems(): array
	{
		return $this->items;
	}

	/**
	 * @return string[] Tokens the model is asked for, in the order of the declarations.
	 */
	public function getTokensToGenerate(): array
	{
		$tokens = [];
		foreach ($this->items as $item)
		{
			if ($item['action'] === self::ACTION_GENERATE)
			{
				$tokens[] = $item['token'];
			}
		}

		return $tokens;
	}

	private static function resolveAction(
		array $declaration,
		string $token,
		string $prompt,
		string $aspect,
		?Manifest $current
	): string
	{
		if ((string)($declaration['kind'] ?? '') === Package::IMAGE_KIND_EXTERNAL)
		{
			return self::ACTION_DOWNLOAD;
		}
		if ($prompt === '')
		{
			return self::ACTION_KEEP_FAILED;
		}
		if (!empty($declaration['regenerate']))
		{
			return self::ACTION_GENERATE;
		}

		$previous = $current?->findImage($token);
		if (
			$previous === null
			|| (string)($previous['kind'] ?? '') !== Package::IMAGE_KIND_GENERATE
			|| trim((string)($previous['prompt'] ?? '')) !== $prompt
			|| (string)($previous['aspect'] ?? '') !== $aspect
		)
		{
			return self::ACTION_GENERATE;
		}

		return match ($previous['outcome'])
		{
			Manifest::OUTCOME_GENERATED => $current->findAssetByToken($token)
				? self::ACTION_REUSE
				: self::ACTION_GENERATE,
			Manifest::OUTCOME_FAILED => self::ACTION_KEEP_FAILED,
			default => self::ACTION_GENERATE,
		};
	}
}
