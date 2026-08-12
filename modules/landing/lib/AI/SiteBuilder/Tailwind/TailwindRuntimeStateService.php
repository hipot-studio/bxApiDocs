<?php
namespace Bitrix\Landing\AI\SiteBuilder\Tailwind;

use Bitrix\Landing\Hook;
use Bitrix\Landing\Landing;

final class TailwindRuntimeStateService
{
	public const MODE_BROWSER_RUNTIME = 'browser_runtime';
	public const INIT_MARKER = 'ai-tailwind-runtime=0';
	public const MANAGED_FILE_MARKER = 'ai-tailwind-runtime=1';
	public const STAGE_RUNTIME_NOT_INITIALIZED = 'runtime_not_initialized';
	public const STAGE_RUNTIME_INITIALIZED = 'runtime_initialized';
	public const STAGE_CSS_SAVED = 'css_saved';
	public const STAGE_CUSTOM_CSS_FILE = 'custom_css_file';
	public const STAGE_RUNTIME_NOT_ALLOWED = 'runtime_not_allowed';
	public const STAGE_LANDING_MISSING = 'landing_missing';
	public const STAGE_LANDING_NOT_FOUND = 'landing_not_found';

	/**
	 * @return array{
	 * 	success: bool,
	 * 	mode: string,
	 * 	stage: string,
	 * 	landingId: int,
	 * 	cssFile: string,
	 * 	marker: string,
	 * 	initialized: bool,
	 * 	saved: bool,
	 * 	source: string,
	 * 	message?: string,
	 * 	error?: string
	 * }
	 */
	public function getLandingState(int $landingId): array
	{
		if ($landingId <= 0)
		{
			return $this->buildBaseState($landingId, self::STAGE_LANDING_MISSING, '')
				+ [
					'message' => 'Не указан ID лендинга.',
					'error' => 'landing_missing',
				];
		}

		$landing = Landing::createInstance($landingId, [
			'check_permissions' => false,
		]);
		if (!$landing->exist())
		{
			return $this->buildBaseState($landingId, self::STAGE_LANDING_NOT_FOUND, '')
				+ [
					'message' => 'Лендинг не найден.',
					'error' => 'landing_not_found',
				];
		}

		$previousHookEditMode = Hook::getEditMode();
		try
		{
			Hook::setEditMode();
			$hookData = Hook::getData($landingId, Hook::ENTITY_TYPE_LANDING, true);
		}
		finally
		{
			Hook::setEditMode($previousHookEditMode);
		}

		$cssFile = trim((string)($hookData['CSSBLOCK']['FILE']['VALUE'] ?? ''));

		return self::describeCssFileState($landingId, $cssFile);
	}

	/**
	 * @return array{
	 * 	success: bool,
	 * 	mode: string,
	 * 	stage: string,
	 * 	landingId: int,
	 * 	cssFile: string,
	 * 	marker: string,
	 * 	initialized: bool,
	 * 	saved: bool,
	 * 	source: string,
	 * 	message?: string,
	 * 	error?: string
	 * }
	 */
	public static function describeCssFileState(int $landingId, string $cssFile): array
	{
		$cssFile = trim($cssFile);
		$state = self::buildBaseState($landingId, self::STAGE_RUNTIME_NOT_INITIALIZED, $cssFile);

		if ($cssFile === '')
		{
			return $state + [
				'message' => 'Browser Tailwind runtime ещё не инициализирован.',
				'error' => 'runtime_not_initialized',
			];
		}

		if (mb_stripos($cssFile, self::MANAGED_FILE_MARKER) !== false)
		{
			return array_merge($state, [
				'success' => true,
				'stage' => self::STAGE_CSS_SAVED,
				'marker' => self::MANAGED_FILE_MARKER,
				'initialized' => true,
				'saved' => true,
				'source' => 'managed_file',
				'message' => 'Tailwind CSS сохранён в managed file.',
			]);
		}

		if (mb_stripos($cssFile, self::INIT_MARKER) !== false)
		{
			return array_merge($state, [
				'success' => true,
				'stage' => self::STAGE_RUNTIME_INITIALIZED,
				'marker' => self::INIT_MARKER,
				'initialized' => true,
				'saved' => false,
				'source' => 'init_marker',
				'message' => 'Browser Tailwind runtime инициализирован.',
			]);
		}

		return array_merge($state, [
			'stage' => self::STAGE_CUSTOM_CSS_FILE,
			'source' => 'custom_css_file',
			'message' => 'CSSBLOCK_FILE уже задан, marker ai-tailwind-runtime не найден.',
			'error' => 'cssblock_file_already_set',
		]);
	}

	/**
	 * @return array{
	 * 	success: bool,
	 * 	mode: string,
	 * 	stage: string,
	 * 	landingId: int,
	 * 	cssFile: string,
	 * 	marker: string,
	 * 	initialized: bool,
	 * 	saved: bool,
	 * 	source: string
	 * }
	 */
	private static function buildBaseState(int $landingId, string $stage, string $cssFile): array
	{
		return [
			'success' => false,
			'mode' => self::MODE_BROWSER_RUNTIME,
			'stage' => $stage,
			'landingId' => $landingId,
			'cssFile' => $cssFile,
			'marker' => '',
			'initialized' => false,
			'saved' => false,
			'source' => 'unknown',
		];
	}
}
