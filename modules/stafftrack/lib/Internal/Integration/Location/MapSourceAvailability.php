<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Integration\Location;

use Bitrix\Location\Infrastructure\SourceCodePicker;
use Bitrix\Location\Service\SourceService;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;

/**
 * Whether the portal has a map to place a new office on.
 *
 * The question goes to the service the address editor of `location` runs on, and not to the OSM source
 * in particular: the editor works on whichever source the portal has selected, and the service already
 * drops a selected source it cannot reach. So the source it answers with is exactly the one an office
 * could be put on, and nothing else has to be checked.
 *
 * Everything reached in `location` is internal to it, so a throw of it is an answer as much as a null
 * is: neither leaves a map to draw.
 *
 * Asking is what costs: the service drops the selected source by its own availability, and for OSM that
 * availability is a token, fetched from the gateway over HTTP whenever the stored one is missing or
 * about to expire. The directory of offices is read far more often than the portal changes its mind
 * about maps, so the answer is kept for minutes instead of being asked for on every reading. Both
 * answers are kept, the refusal as much as the source - a gateway that cannot be reached is exactly the
 * answer that must not be paid for twice.
 *
 * The code of the selected source is what the answer is kept under, so a portal that selects another
 * source is told about the one it has selected now and not about the one it left.
 */
class MapSourceAvailability
{
	private const CACHE_TTL = 300;
	private const CACHE_DIR = '/stafftrack/map_source';
	private const CACHE_KEY = 'isConfigured';

	/**
	 * The whole reach into `location` stands behind this catch: the directory of offices is read through
	 * here and has to stay readable whatever the module has become. A throw of the service alone is caught
	 * deeper, in {@see askTheService()}, where the refusal it stands for is kept like any other answer.
	 */
	public function isConfigured(): bool
	{
		try
		{
			return $this->rememberedAnswer();
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function rememberedAnswer(): bool
	{
		$sourceCode = $this->selectedSourceCode();
		if ($sourceCode === null)
		{
			return false;
		}

		$cache = Cache::createInstance();
		$cacheId = 'map_source_' . $sourceCode;

		if ($cache->initCache(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
		{
			$remembered = $cache->getVars();
			if (is_array($remembered) && array_key_exists(self::CACHE_KEY, $remembered))
			{
				return (bool)$remembered[self::CACHE_KEY];
			}
		}

		$isConfigured = $this->askTheService();

		if ($cache->startDataCache(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
		{
			$cache->endDataCache([self::CACHE_KEY => $isConfigured]);
		}

		return $isConfigured;
	}

	private function askTheService(): bool
	{
		try
		{
			return $this->findSource() !== null;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	/**
	 * The source the portal has selected, by name alone: the selection is read without the source behind
	 * it being built, which is why it costs nothing and may decide what the answer is kept under. A
	 * portal without the module of maps has selected nothing, and nothing is what it can offer an office
	 * to be placed on.
	 */
	protected function selectedSourceCode(): ?string
	{
		if (!Loader::includeModule('location'))
		{
			return null;
		}

		return SourceCodePicker::getSourceCode();
	}

	/**
	 * The one reach into `location`: the source it answers with, or nothing at all. The type is loose
	 * because the source itself is never read here, only its presence. The module is already on by the
	 * time this is asked - the selection above answers for a portal that has none.
	 */
	protected function findSource(): ?object
	{
		return SourceService::getInstance()->getSource();
	}
}
