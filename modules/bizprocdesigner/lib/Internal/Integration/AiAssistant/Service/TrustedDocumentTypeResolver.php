<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\ActivityVisibilityFilter;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Cache\BlockDescriptionCache;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentTemplate;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\BaselineProjection;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

/**
 * The document types a request bound to one template is allowed to name, and the single place that rule lives.
 *
 * Its purpose is narrow and worth stating, because the name invites a wider reading: the set stops a graph
 * from widening its own reach, it is NOT the boundary of access to another entity's metadata. That boundary is
 * the permission check on the type itself ({@see DocumentAccessService::canViewDocumentType()}), and the two are
 * applied in that order - a type outside the set is refused before anyone's rights are consulted. For a portal
 * with crm installed the set holds lead, contact, company, deal, quote, invoice and the task type regardless of
 * which of them the bound template runs on, because the complex nodes declare their type at class level.
 *
 * Every source is one the server owns:
 *  - the type of the bound template itself;
 *  - the canonical types the same filtered catalog publishes to this token - the fixed document of a complex
 *    node, the document a trigger returns, and the type a preset pins into a `document_type` setting;
 *  - the exact values of `document_type` settings carried by the server-loaded PUBLISHED version of the bound
 *    template, and only where the schema of that block really gives the setting that type.
 *
 * The current draft, the graph of the request and the description context are not sources and never become
 * ones: a graph that could add to this set would be authorizing itself.
 */
class TrustedDocumentTypeResolver
{
	/**
	 * How long a set may be served from the storage before it is built again whatever the key says.
	 *
	 * Not the shelf life of the data - the key already answers for everything it was built from that can be seen.
	 * This is the bound on the drift of what CANNOT be seen: a portal feature switched at runtime, which decides
	 * the visibility of an activity without touching a file or the list of installed modules
	 * ({@see self::catalogFingerprint()}), and a folder of the catalog added by bizproc, which the mirrored
	 * {@see self::ACTIVITY_FOLDERS} would not know to look in. Both make the set answer with what a portal no
	 * longer has - refusing a type that has just become legitimate, or admitting one that has stopped being so -
	 * and against neither is there a source cheaper than the walk this exists to save.
	 *
	 * Five minutes, because that is the cost of the error rather than of the miss: an agent works in bursts and
	 * keeps the set warm for the whole of one, and a burst that starts after the wait rebuilds it for about what
	 * every single request cost before there was a stored set at all (~1 s on this portal).
	 */
	private const CROSS_REQUEST_TTL = 300;

	/**
	 * How long the state of the catalog is taken on trust. Short, because it is what tells a set built before a
	 * portal changed from one built after: a catalog edit is answered within the minute, and the listing behind
	 * the answer costs more than the read it guards on a network file system.
	 */
	private const CATALOG_FINGERPRINT_TTL = 60;

	/** Sharded by bound template, so forgetting one template's sets never touches another's. */
	private const CACHE_PATH = '/bizprocdesigner/ai-agent/trusted-document-types';

	/** Kept beside the sets rather than among them: it belongs to the portal, not to any one template. */
	private const CATALOG_CACHE_PATH = self::CACHE_PATH . '/catalog';

	/**
	 * Where the catalog of activities is read from, mirroring {@see \Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher}.
	 * Only their listing is read here, never their contents: this is what answers whether the catalog behind an
	 * already built set still stands.
	 */
	private const ACTIVITY_FOLDERS = [
		'/local/activities',
		'/local/activities/custom',
		BX_ROOT . '/activities/custom',
		BX_ROOT . '/activities/bitrix',
		BX_ROOT . '/modules/bizproc/activities',
	];

	private const ACTIVITY_DESCRIPTION_FILE = '.description.php';

	/**
	 * @var array<string, array<string, DocumentDescription>> the built sets of this request, keyed by bound
	 *      type and template. The set is built once per request per bound template (BRIEF § 9): both entry
	 *      points that consult it - the document field read and the catalog description context - would
	 *      otherwise walk the catalog again for the same answer.
	 */
	private static array $cache = [];

	/** @var string|null the state of the catalog, read once per request. */
	private static ?string $catalogFingerprint = null;

	private readonly AgentWorkflowResolverService $resolverService;
	private readonly ?BlockDescriptionCache $descriptions;

	/**
	 * @param BlockDescriptionCache|null $descriptions what answers whether a setting of the baseline really
	 *        carries a document type. Null builds one per baseline read ({@see self::describing()}), which is
	 *        where the block types its barriers are judged against are known; one kept across baselines would
	 *        answer for a template out of the schemas another one unlocked.
	 */
	public function __construct(
		?AgentWorkflowResolverService $resolverService = null,
		?BlockDescriptionCache $descriptions = null,
	)
	{
		$this->resolverService = $resolverService ?? Container::getAgentWorkflowResolverService();
		$this->descriptions = $descriptions;
	}

	/**
	 * Whether the bound template may name this type at all. Says nothing about the caller's rights on it.
	 */
	public function isAllowed(DocumentDescription $boundType, ?int $templateId, DocumentDescription $candidate): bool
	{
		return array_key_exists($candidate->toCanonicalString(), $this->allowedTypes($boundType, $templateId));
	}

	/**
	 * @return array<string, DocumentDescription> keyed by the canonical spelling of the type
	 */
	public function allowedTypes(DocumentDescription $boundType, ?int $templateId): array
	{
		$cacheKey = $boundType->toCanonicalString() . '#' . ($templateId ?? 0);
		if (array_key_exists($cacheKey, self::$cache))
		{
			return self::$cache[$cacheKey];
		}

		$types = [$boundType->toCanonicalString() => $boundType];
		if (Loader::includeModule('bizproc'))
		{
			$types += $this->declaredTypes($boundType, $templateId);
		}

		self::$cache[$cacheKey] = $types;

		return $types;
	}

	/**
	 * The two declared sources of the set, served across requests.
	 *
	 * The walk behind them costs seconds - the catalog is read descriptor by descriptor - and it is paid before
	 * the permission check the order of the read deliberately puts second, so a refusal costs as much as an
	 * answer. What is stored is only what the server itself declared; the order in which the read judges
	 * membership and rights is untouched by this.
	 *
	 * A stored set is served only while both of its sources still stand: the key carries the revision of the
	 * PUBLISHED bound template beside the state of the catalog, so a publication that adds a type to the set
	 * asks a different key rather than being answered by the set built before it. The alternative - a key blind
	 * to one of them - would refuse a type that has just become legitimate, which is worse than the walk this
	 * saves.
	 *
	 * @return array<string, DocumentDescription>
	 */
	private function declaredTypes(DocumentDescription $boundType, ?int $templateId): array
	{
		$cache = Cache::createInstance();
		$key = self::crossRequestKey($boundType, $templateId, $this->publishedRevision($templateId));
		$path = self::cachePath($templateId);

		if ($cache->initCache(self::CROSS_REQUEST_TTL, $key, $path))
		{
			$stored = self::restore($cache->getVars());
			if ($stored !== null)
			{
				return $stored;
			}
		}

		$types = $this->fromCatalog($boundType);
		$baseline = $this->publishedBaseline($templateId);
		if ($baseline !== null)
		{
			$types += $this->typesDeclaredBy($boundType, $baseline);
		}

		// Forced, because this branch is also reached with a valid entry standing: one holding what
		// self::restore() could not read. Without it startDataCache() would init the very same key again,
		// find that entry and refuse to start, leaving the unreadable one to be rebuilt around on every
		// request until its TTL runs out - the walk this exists to save.
		$cache->forceRewriting(true);
		if ($cache->startDataCache())
		{
			// Wrapped rather than stored bare: an empty set is a legitimate answer for a bound type whose
			// catalog declares nothing, and bare it would read as a miss on every request that follows.
			$cache->endDataCache(['types' => array_keys($types)]);
		}
		$cache->forceRewriting(false);

		return $types;
	}

	/**
	 * Forgets the sets built so far. For tests and for a long-running process that serves several requests.
	 *
	 * The stored sets are not dropped here and need no dropping: they are reached by a key that already answers
	 * for everything they were built from, so a set that no longer stands is not found rather than found stale.
	 */
	public static function clearCache(): void
	{
		self::$cache = [];
		self::$catalogFingerprint = null;
	}

	/**
	 * The types the catalog of this bound template publishes, read off the descriptors of the very same
	 * filtered catalog the agent is given.
	 *
	 * The descriptors are read, not the ai descriptions: building the ai description of every block costs a
	 * dialog render per block, and the three channels a type travels in are all declared in the descriptor.
	 *
	 * @return array<string, DocumentDescription>
	 */
	private function fromCatalog(DocumentDescription $boundType): array
	{
		$documentBizprocType = $boundType->toBizprocComplexType();

		$activities = $this->readCatalog($documentBizprocType);

		$complexActivityService = BizprocContainer::instance()->getComplexActivityService();

		$types = [];
		foreach ($activities as $type => $activityDescription)
		{
			/** @var ActivityDescription $activityDescription */
			if (
				$activityDescription->getExcluded()
				|| $activityDescription->getDeprecated()
				|| !ActivityVisibilityFilter::isNodeCompatible($activityDescription)
			)
			{
				continue;
			}

			if ($activityDescription->getNodeType() === ActivityNodeType::COMPLEX->value)
			{
				self::collect($types, $complexActivityService->getFixedDocumentTypeForNodeAction((string)$type));
			}

			foreach ($activityDescription->getReturn() as $field)
			{
				if (is_array($field) && ($field['Type'] ?? '') === FieldType::DOCUMENT)
				{
					self::collect($types, $field['Default'] ?? null);
				}
			}

			// What a preset pins into a `document_type` setting - `Document` of the crm entity triggers, which
			// is how the deal catalog publishes the lead, the contact and the company. Read by the shape of
			// the value rather than by the type of the setting, because knowing the type would cost the ai
			// description of every block in the catalog. The looseness costs nothing here: a preset is a
			// server-owned descriptor shipped with the module, never anything a graph writes, so a stray
			// value that happened to parse could not be planted - and admitting one would still leave the
			// permission check standing between the caller and that type.
			foreach (($activityDescription->getPresets() ?? []) as $preset)
			{
				foreach (($preset['PROPERTIES'] ?? []) as $value)
				{
					self::collect($types, $value);
				}
			}
		}

		return $types;
	}

	/**
	 * The catalog this bound type is offered, kept apart from the reading of it above so that the walks can be
	 * counted: they are unobservable from the answers of the resolver, and what the cache exists to save is
	 * exactly one of them.
	 *
	 * @param array{0: string, 1: string, 2: string} $documentBizprocType
	 *
	 * @return iterable<string, ActivityDescription>
	 */
	protected function readCatalog(array $documentBizprocType): iterable
	{
		return BizprocContainer::instance()
			->getActivitySearcherService()
			->searchByType(
				[
					ActivityType::NODE->value,
					ActivityType::TRIGGER->value,
				],
				$documentBizprocType,
			)
			->computeDescriptionFilter($documentBizprocType)
		;
	}

	/**
	 * What the bound template has published, told apart from what it published before, and told cheaply: the
	 * stored row of the template, never the agent projection of it.
	 *
	 * The projection is what the set is really built from, but building it converts the graph, and converting
	 * one walks the catalog - the very walk the stored set exists to save. The row behind it answers the only
	 * question the key has to ask, "is this still the graph the set was built from", for a millisecond and a
	 * half instead of two seconds.
	 *
	 * Read by id alone, on purpose: {@see AgentWorkflowResolverService::getPublishedAgentTemplate()} narrows
	 * its own read further, and a revision that saw LESS than the projection does would leave an edit the
	 * projection acts on invisible to the key.
	 */
	protected function publishedRevision(?int $templateId): string
	{
		if ($templateId === null)
		{
			return '-';
		}

		$published = WorkflowTemplateTable::query()
			->setSelect(['TEMPLATE'])
			->where('ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		return $published === false ? '0' : md5(serialize($published['TEMPLATE'] ?? null));
	}

	/**
	 * The published version of the bound template, the only graph this set reads: the draft and the submitted
	 * graph are what it exists to keep out.
	 *
	 * Read only where the set is really built again - the conversion behind it costs about as much as the walk
	 * of the catalog beside it, and a request answered from the stored set pays neither.
	 */
	private function publishedBaseline(?int $templateId): ?AgentTemplate
	{
		if ($templateId === null)
		{
			return null;
		}

		$publishedTemplate = $this->resolverService->getPublishedAgentTemplate($templateId);

		return $publishedTemplate instanceof AgentTemplate ? $publishedTemplate : null;
	}

	/**
	 * The document types a graph names in its `document_type` settings, judged against the schema of each
	 * block that names one.
	 *
	 * A value is admitted only after the schema of its own block confirms the setting really carries a document
	 * type, so a free-text setting that happens to hold three at-separated words adds nothing. That confirmation
	 * costs a block description, so it is asked for only about a value that already parses - which on a template
	 * naming no foreign type is never.
	 *
	 * @return array<string, DocumentDescription>
	 */
	private function typesDeclaredBy(DocumentDescription $boundType, AgentTemplate $template): array
	{
		$descriptions = $this->descriptions ?? self::describing($template);

		$types = [];
		foreach ($template->blocks as $block)
		{
			/** @var AgentBlock $block */
			foreach ($block->settings as $setting)
			{
				$candidate = is_string($setting->value)
					? DocumentDescription::fromCanonicalString($setting->value)
					: null
				;
				if ($candidate === null)
				{
					continue;
				}

				if ($this->isDocumentTypeSetting($descriptions, $boundType, $block, $setting->name))
				{
					$types[$candidate->toCanonicalString()] = $candidate;
				}
			}
		}

		return $types;
	}

	/**
	 * The schemas the baseline is judged by, told which block types they may still be asked about.
	 *
	 * The Rest profile hides a deprecated type from every graph but the one that has already published it, and
	 * lifts that barrier - and the EXCLUDED one beside it - for exactly the block types of the published
	 * baseline ({@see BlockDescriptionService::getBlockSettings()}). Asked without them, the schema of a
	 * deprecated block of the baseline is refused rather than answered, so the type that block declares drops
	 * out of the set and a legitimate document type starts answering DocumentTypeNotAllowed - the regression
	 * the key of the stored set guards against, arriving from the other side.
	 *
	 * Nothing is widened by that: the walk this serves reads the published version alone, so every type it
	 * asks about is one the baseline already carries. The set is read off that very baseline, by the one rule
	 * every other consumer of it reads it ({@see BaselineProjection::blockTypes()}).
	 */
	private static function describing(AgentTemplate $baseline): BlockDescriptionCache
	{
		return new BlockDescriptionCache(
			source: RequestSource::Rest,
			baselineBlockTypes: (new BaselineProjection($baseline->blocks))->blockTypes(),
		);
	}

	/** Whether the schema of this block gives the named setting the document-type type. */
	private function isDocumentTypeSetting(
		BlockDescriptionCache $descriptions,
		DocumentDescription $boundType,
		AgentBlock $block,
		string $settingName,
	): bool
	{
		$detail = $descriptions->get($block->type, $boundType, $block->presetId);
		$setting = $detail?->settings->findFirstByName($settingName);

		return $setting !== null && (string)$setting->type === FieldType::DOCUMENT_TYPE;
	}

	/**
	 * What a stored set is answered by: the bound type and its template, and the state of both sources the set
	 * is built from.
	 */
	private static function crossRequestKey(
		DocumentDescription $boundType,
		?int $templateId,
		string $publishedRevision,
	): string
	{
		return md5(implode('|', [
			$boundType->toCanonicalString(),
			$templateId ?? 0,
			$publishedRevision,
			self::catalogFingerprint(),
		]));
	}

	/** Kept apart per template, so forgetting the sets of one never reaches the sets of another. */
	private static function cachePath(?int $templateId): string
	{
		return self::CACHE_PATH . '/' . ($templateId ?? 0);
	}

	/**
	 * The state of the activity catalog, as cheaply as it can be told without reading the catalog itself: which
	 * activity directories carry a descriptor, when each descriptor last changed, which modules are installed,
	 * and which edition of the rules below reads them.
	 *
	 * The listing is what a full walk costs three orders of magnitude more to learn, and the installed modules are
	 * beside it because a descriptor's own visibility follows them ({@see \Bitrix\Bizproc\Activity\ActivityDescription::getExcluded()}
	 * commonly answers `!Loader::includeModule(...)`). A portal feature switched at runtime is the one source of
	 * drift it cannot see at all, which is what {@see self::CROSS_REQUEST_TTL} bounds.
	 *
	 * The listing itself is held for {@see self::CATALOG_FINGERPRINT_TTL}, so a catalog edit is answered within the
	 * minute rather than instantly: reading it costs a quarter of a second on a network file system, which is most
	 * of what a stored set was meant to save.
	 */
	private static function catalogFingerprint(): string
	{
		if (self::$catalogFingerprint !== null)
		{
			return self::$catalogFingerprint;
		}

		$cache = Cache::createInstance();
		if ($cache->initCache(self::CATALOG_FINGERPRINT_TTL, 'state', self::CATALOG_CACHE_PATH))
		{
			$stored = $cache->getVars();
			if (is_string($stored) && $stored !== '')
			{
				return self::$catalogFingerprint = $stored;
			}
		}

		$fingerprint = self::readCatalogState();
		// Forced for the reason the set above is: a stored value this method cannot read leaves the listing
		// to be walked again on every request until the TTL runs out.
		$cache->forceRewriting(true);
		if ($cache->startDataCache())
		{
			$cache->endDataCache($fingerprint);
		}
		$cache->forceRewriting(false);

		return self::$catalogFingerprint = $fingerprint;
	}

	/** The listing itself, read from disk. @see self::catalogFingerprint() */
	private static function readCatalogState(): string
	{
		$root = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
		$parts = [
			(string)ModuleManager::getVersion('bizproc'),
			(string)ModuleManager::getVersion('bizprocdesigner'),
			implode(',', array_keys(ModuleManager::getInstalledModules())),
			// The rules below are half of what a set holds, so a set built by another edition of them is
			// another set. Beside the module versions rather than instead of them: those do not move while a
			// developer is editing this file, and a stored set outliving an edit of its own rules is a
			// debugging trap.
			(string)@filemtime(__FILE__),
		];

		foreach (self::ACTIVITY_FOLDERS as $folder)
		{
			// Read with the plain functions rather than through the IO layer: this runs before every read the
			// cache serves, and the objects that layer builds per directory entry cost more than the answer.
			$children = @scandir($root . $folder);
			if ($children === false)
			{
				continue;
			}

			foreach ($children as $name)
			{
				$modified = @filemtime($root . $folder . '/' . $name . '/' . self::ACTIVITY_DESCRIPTION_FILE);
				if ($modified !== false)
				{
					$parts[] = $name . ':' . $modified;
				}
			}
		}

		return md5(implode("\n", $parts));
	}

	/**
	 * The stored set as types again, or null when the storage answers with anything this class did not write -
	 * a spelling the parser refuses included, since a key nothing can ever match would silently stop protecting
	 * whatever it was meant to be.
	 *
	 * @return array<string, DocumentDescription>|null
	 */
	private static function restore(mixed $stored): ?array
	{
		if (!is_array($stored) || !is_array($stored['types'] ?? null))
		{
			return null;
		}

		$types = [];
		foreach ($stored['types'] as $canonical)
		{
			$description = is_string($canonical) ? DocumentDescription::fromCanonicalString($canonical) : null;
			if ($description === null)
			{
				return null;
			}

			$types[$description->toCanonicalString()] = $description;
		}

		return $types;
	}

	/**
	 * Admits one declared value into the set, in either spelling a descriptor writes it in: the canonical
	 * string a preset pins, and the `[module, entity, documentType]` triplet a return field defaults to.
	 *
	 * @param array<string, DocumentDescription> $types
	 */
	private static function collect(array &$types, mixed $value): void
	{
		if (is_array($value))
		{
			$value = count($value) === 3 && array_is_list($value) && !in_array(false, array_map('is_string', $value), true)
				? implode(DocumentDescription::CANONICAL_SEPARATOR, $value)
				: null
			;
		}

		$description = is_string($value) ? DocumentDescription::fromCanonicalString($value) : null;
		if ($description !== null)
		{
			$types[$description->toCanonicalString()] = $description;
		}
	}
}
