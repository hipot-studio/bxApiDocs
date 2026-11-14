<?php

namespace Bitrix\Mobile\Internal\Onboarding\Config;

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

/**
 * Bundled default: a valid campaign document of the schema the remote admin service
 * publishes, shipped with the module as data/onboarding/campaign-config.json. Used as the
 * fallback on first run and whenever the cached Option is missing or broken, so the series
 * keeps running fully offline. The document is data only - the file is read here and parsed
 * by JsonCampaignConfigProvider, exactly like a remote one.
 *
 * What the document declares is deliberately NOT described here. It is a separate file that
 * changes without touching this loader, and a description kept next to the loader drifts on
 * the first such change - as the list of "keys the engine does not read" already had, by the
 * time the engine started reading the deeplinks and the click analytics of both steps and
 * finals. Every key is read in one place and explained there: StepConfig, FinalPushConfig,
 * ScheduleConfig, and the acceptance criteria in JsonCampaignConfigProvider.
 */
class DefaultCampaignConfig
{
	/**
	 * Addressed the way Loader::getLocal() takes it - relative to /local/ or /bitrix/ -
	 * because the module may be installed in either.
	 */
	private const DOCUMENT_FILE = 'modules/mobile/data/onboarding/campaign-config.json';

	/**
	 * Parsed document per class: a descendant naming a file of its own gets an entry of its
	 * own instead of the answer the parent has already cached.
	 *
	 * @var array<class-string, array<string, mixed>>
	 */
	private static array $documents = [];

	/**
	 * @return array<string, mixed>
	 */
	public static function get(): array
	{
		return self::$documents[static::class] ??= self::readDocument(static::getDocumentPath());
	}

	/**
	 * Where the document lies. A method rather than a path built at the point of the read:
	 * a test - or a portal keeping a document elsewhere - states its own file here, and
	 * nothing else in the class has to know about it.
	 */
	protected static function getDocumentPath(): string
	{
		$path = Loader::getLocal(self::DOCUMENT_FILE);

		// getLocal() answers false when the file is in neither root; the relative name goes
		// on to the read, which reports it as unreadable and leaves the campaign off
		return is_string($path) ? $path : self::DOCUMENT_FILE;
	}

	/**
	 * Fail-closed: a missing, unreadable or malformed file yields an empty document,
	 * which has no meta.enabled and therefore leaves the region unsupported - the
	 * campaign is off instead of the reader failing mid-pass.
	 *
	 * Only "the file reads" and "this is a JSON object" are asked here on purpose. The
	 * structure is judged by JsonCampaignConfigProvider::isUsableDocument(), which every
	 * cached and fetched document has to pass before it is read at all; the bundled one is
	 * the last resort and is accepted, but its flaws are counted the same way and logged as
	 * `bundled document is incomplete`. The file itself is pinned by DefaultCampaignConfigTest,
	 * so it answers for its shape on CI rather than on a portal.
	 *
	 * @return array<string, mixed>
	 */
	private static function readDocument(string $path): array
	{
		$raw = is_readable($path) ? file_get_contents($path) : false;
		if ($raw === false)
		{
			self::logFailure('cannot read ' . $path);

			return [];
		}

		try
		{
			$document = Json::decode($raw);
		}
		catch (\Throwable $exception)
		{
			self::logFailure('malformed document ' . $path . ': ' . $exception->getMessage());

			return [];
		}

		if (!is_array($document))
		{
			self::logFailure('document ' . $path . ' is not an object');

			return [];
		}

		return $document;
	}

	private static function logFailure(string $reason): void
	{
		AddMessage2Log('Onboarding campaign config: bundled document unavailable: ' . $reason, 'mobile');
	}
}
