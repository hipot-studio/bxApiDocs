<?php

namespace Bitrix\Landing\Controller;

use Bitrix\Landing\AI\SiteBuilder\Tailwind\TailwindRuntimeCssService;
use Bitrix\Main\Engine;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;

class Tailwind extends Controller
{
	public function getDefaultPreFilters(): array
	{
		return [
			new Engine\ActionFilter\Authentication(),
			new ActionFilter\Extranet(),
		];
	}

	public function saveCssAction(int $landingId, string $css, bool $publish = true): array
	{
		$result = (new TailwindRuntimeCssService())->saveLandingCss($landingId, $css, [
			'publish' => $publish,
		]);
		if (empty($result['success']))
		{
			$this->addError(new Error(
				'Failed to save Tailwind CSS.',
				(string)($result['error'] ?? 'TAILWIND_CSS_SAVE_FAILED')
			));
		}

		return $result;
	}
}
