<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Services;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Model\GenerationsTable;
use Bitrix\Landing\Rights;
use Bitrix\Main\Config\Option;

final class AiSiteDevBypassService
{
	public const OPTION_ENABLED = 'landing_ai_sites_dev_bypass_enabled';
	public const REQUEST_FIELD = 'devAiSitesBypass';
	public const DATA_KEY = 'devAiSitesBypass';
	private const SOURCE_DEV_AI_BLOCKS = 'dev/public/ai-blocks';

	private function __construct()
	{
	}

	public static function isRequestPayloadEnabled(array $payload): bool
	{
		$value = $payload[self::REQUEST_FIELD] ?? null;

		return $value === 'Y' || $value === true;
	}

	public static function isPortalBypassEnabled(): bool
	{
		return Option::get('landing', self::OPTION_ENABLED, 'N', '') === 'Y';
	}

	public static function setPortalBypassEnabled(bool $enabled): void
	{
		Option::set('landing', self::OPTION_ENABLED, $enabled ? 'Y' : 'N', '');
	}

	public static function isPortalBypassAllowedForCurrentUser(): bool
	{
		return self::isPortalBypassEnabled() && Rights::isAdmin();
	}

	public static function markGeneration(Generation $generation): void
	{
		$generation->setData(self::DATA_KEY, [
			'enabled' => true,
			'source' => self::SOURCE_DEV_AI_BLOCKS,
		]);
	}

	public static function isGenerationMarked(Generation $generation): bool
	{
		return self::isGenerationDataMarked($generation->getData());
	}

	public static function isGenerationIdMarked(int $generationId): bool
	{
		if ($generationId <= 0)
		{
			return false;
		}

		$row = GenerationsTable::query()
			->setSelect(['DATA'])
			->where('ID', '=', $generationId)
			->fetch()
		;

		return self::isGenerationDataMarked($row['DATA'] ?? null);
	}

	public static function isGenerationDataMarked(mixed $generationData): bool
	{
		if (!is_array($generationData))
		{
			return false;
		}

		$data = $generationData[self::DATA_KEY] ?? null;
		if (!is_array($data))
		{
			return false;
		}

		return !empty($data['enabled'])
			&& (string)($data['source'] ?? '') === self::SOURCE_DEV_AI_BLOCKS
		;
	}
}
