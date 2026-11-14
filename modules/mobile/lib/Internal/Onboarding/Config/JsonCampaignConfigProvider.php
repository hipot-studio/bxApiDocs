<?php

namespace Bitrix\Mobile\Internal\Onboarding\Config;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;
use Bitrix\Mobile\Internal\Onboarding\Activation\ActivationPath;
use Bitrix\Mobile\Internal\Onboarding\Activation\ActivationStep;
use Bitrix\Mobile\Internal\Onboarding\Activation\PushRule;
use Bitrix\Mobile\Internal\Onboarding\Checkers\Gate\GateCondition;
use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Schedule\SendWindow;
use Bitrix\Mobile\Internal\Onboarding\Schedule\SeriesRhythm;

/**
 * Reads the campaign document from the Option cache filled by
 * RemoteCampaignConfigFetcher and parses it into the domain model. Never goes to
 * the network: a missing or unusable cache degrades to DefaultCampaignConfig, so
 * the series keeps running offline. A degradation is never silent - see
 * resolveDocument().
 *
 * The document carries data only. Step ids and push type tokens are matched against the
 * ActivationStep/PushType cases declared in code, and a document declaring one the code
 * does not know is refused whole (isUsableDocument) rather than parsed into a shorter
 * path - the document cannot introduce a step, a push type or any logic.
 */
class JsonCampaignConfigProvider implements CampaignConfigProvider
{
	public const MODULE_ID = 'mobile';
	public const OPTION_NAME = 'OnboardingCampaignConfig';

	/**
	 * The region this document belongs to. One option holds one document and RuRegion is the
	 * only region driven by it, so a document authored for another region is not a variant of
	 * this campaign but somebody else's schedule, texts and switch applied to these portals.
	 */
	public const CAMPAIGN_REGION = 'ru';

	/**
	 * Versions of the manifest format this engine implements, and the range a document is
	 * accepted within.
	 *
	 * The format version is the contract between the manifest and the engine; it is not the
	 * revision of the campaign, which says which release of the content a portal runs. Inside
	 * one format version a document may grow keys the engine does not know - it reads only the
	 * keys it knows, so a working portal keeps working. A change that carries semantics the
	 * engine MUST honour raises the format version instead, and a document of a version this
	 * engine does not implement is refused whole: the alternative is honouring half of it
	 * silently.
	 */
	public const MIN_SCHEMA_VERSION = 1;
	public const MAX_SCHEMA_VERSION = 1;

	/** Switches of the schedule section, all optional and all read as booleans. */
	private const SCHEDULE_FLAGS = ['workdaysOnly', 'shiftWeekendToMonday', 'dedupPerDay'];

	/** Intervals of the rhythm of the series, both required. */
	private const REPEAT_INTERVAL_KEYS = ['firstWorkdays', 'thenWorkdays'];

	/** The two halves of the funnel of one push: reported by the server and by the device. */
	private const ANALYTICS_HALVES = ['send', 'click'];

	private const DEFAULT_PATH_CODE = 'default';
	private const DEFAULT_CONTENT_INDUSTRY = '_default';
	private const ANY_ATTEMPT = '*';

	/**
	 * Upper bound on a text the document hands to the push: the longest shipped body is
	 * 100 characters and the longest title 33, so the limit leaves room for any authored
	 * wording while keeping the message bounded.
	 */
	private const MAX_CONTENT_LENGTH = 512;

	/** @var array<string, mixed>|null */
	private ?array $document = null;

	/** @var array<string, ActivationPath>|null */
	private ?array $paths = null;

	private ?ScheduleConfig $scheduleConfig = null;

	/**
	 * Steps already looked up by push type, including the types no step targets. The pipeline
	 * asks for the same handful of types once per user and again per send, and the document
	 * cannot change under a running pass.
	 *
	 * @var array<string, StepConfig|null>
	 */
	private array $stepsByPushType = [];

	public function resolvePath(array $toolCodes): ActivationPath
	{
		$paths = $this->getPaths();
		$winner = null;

		foreach ($paths as $path)
		{
			if (array_intersect($path->getToolCodes(), $toolCodes) === [])
			{
				continue;
			}

			if ($winner === null || $path->getPriority() > $winner->getPriority())
			{
				$winner = $path;
			}
		}

		return $winner ?? $paths[self::DEFAULT_PATH_CODE] ?? self::createEmptyPath();
	}

	/**
	 * Reachable only when even the bundled document is unusable (its file is missing or
	 * malformed): a path without steps makes the activation engine send nothing, whereas
	 * a missing default path used to break the reader itself.
	 */
	private static function createEmptyPath(): ActivationPath
	{
		return new ActivationPath(self::DEFAULT_PATH_CODE, [], []);
	}

	public function getPaths(): array
	{
		$this->paths ??= $this->parsePaths($this->getDocument());

		return $this->paths;
	}

	public function getMeta(): array
	{
		return $this->getSection('meta');
	}

	public function getStepConfig(string $stepId): ?array
	{
		$node = $this->getSection('steps')[$stepId] ?? null;

		return is_array($node) ? $node : null;
	}

	public function findStepByPushType(PushType $pushType): ?StepConfig
	{
		$token = $pushType->getConfigToken();
		if ($token === null)
		{
			return null;
		}

		if (array_key_exists($token, $this->stepsByPushType))
		{
			return $this->stepsByPushType[$token];
		}

		$this->stepsByPushType[$token] = null;
		foreach ($this->getSection('steps') as $node)
		{
			if (is_array($node) && ($node['pushType'] ?? null) === $token)
			{
				$this->stepsByPushType[$token] = StepConfig::fromStepNode($node);

				break;
			}
		}

		return $this->stepsByPushType[$token];
	}

	public function getGateCondition(ActivationStep $step): ?GateCondition
	{
		$node = $this->getStepConfig($step->value);

		return $node === null ? null : StepConfig::fromStepNode($node)->gate;
	}

	public function getContent(string $pushType, ?string $industry = null, string $attemptKey = self::ANY_ATTEMPT): ?array
	{
		$byIndustry = $this->getSection('content')[$pushType] ?? null;
		if (!is_array($byIndustry))
		{
			return null;
		}

		return $this->pickContentLeaf($this->pickIndustryBucket($byIndustry, $industry), $attemptKey);
	}

	public function getFinalContent(string $final, ?string $industry = null): ?array
	{
		$byIndustry = $this->getSection('finals')[$final]['content'] ?? null;
		if (!is_array($byIndustry))
		{
			return null;
		}

		return $this->pickContentLeaf($this->pickIndustryBucket($byIndustry, $industry), self::ANY_ATTEMPT);
	}

	public function getSchedule(): array
	{
		return $this->getSection('schedule');
	}

	public function getScheduleConfig(): ScheduleConfig
	{
		$this->scheduleConfig ??= ScheduleConfig::fromSchedule($this->getSchedule());

		return $this->scheduleConfig;
	}

	public function getFinals(): array
	{
		return $this->getSection('finals');
	}

	public function getAnalytics(): array
	{
		return $this->getSection('analytics');
	}

	/**
	 * An over-long text is treated as unusable content, the same way a missing one is: the
	 * push is not sent rather than sent trimmed, so a document with runaway text cannot
	 * put an arbitrarily long message on the device.
	 *
	 * @param array<string, mixed> $bucket
	 * @return array{title: string, body: string}|null
	 */
	private function pickContentLeaf(array $bucket, string $attemptKey): ?array
	{
		$leaf = $bucket[$attemptKey] ?? $bucket[self::ANY_ATTEMPT] ?? null;
		if (!is_array($leaf) || !isset($leaf['title'], $leaf['body']))
		{
			return null;
		}

		$title = (string)$leaf['title'];
		$body = (string)$leaf['body'];
		if (!self::isWithinLengthLimit($title) || !self::isWithinLengthLimit($body))
		{
			return null;
		}

		return [
			'title' => $title,
			'body' => $body,
		];
	}

	private static function isWithinLengthLimit(string $text): bool
	{
		return mb_strlen($text) <= self::MAX_CONTENT_LENGTH;
	}

	/**
	 * `industryAliases` maps regional variants of the same industry onto one authored
	 * bucket: the qualification wizard offers `government-organization` in BY and
	 * `government-or-municipal-organization` in RU and the rest of the CIS, so a portal
	 * ever sends only one of them. The text is authored once, and a document for another
	 * region may author the variant directly instead of aliasing it.
	 *
	 * @param array<string, mixed> $byIndustry
	 * @return array<string, mixed>
	 */
	private function pickIndustryBucket(array $byIndustry, ?string $industry): array
	{
		return self::resolveIndustryBucket($this->getDocument(), $byIndustry, $industry);
	}

	/**
	 * @param array<string, mixed> $document
	 * @param array<string, mixed> $byIndustry
	 * @return array<string, mixed>
	 */
	private static function resolveIndustryBucket(array $document, array $byIndustry, ?string $industry): array
	{
		$defaultIndustry = $document['defaultIndustry'] ?? null;
		$alias = $industry === null ? null : ($document['industryAliases'][$industry] ?? null);
		$candidates = [
			$industry,
			is_string($alias) ? $alias : null,
			is_string($defaultIndustry) ? $defaultIndustry : null,
			self::DEFAULT_CONTENT_INDUSTRY,
		];

		foreach ($candidates as $candidate)
		{
			if ($candidate !== null && is_array($byIndustry[$candidate] ?? null))
			{
				return $byIndustry[$candidate];
			}
		}

		return [];
	}

	/**
	 * Paths that resolve to at least one usable step. A path with no usable step is
	 * dropped, so its tool codes safely land on the default path - which only happens for
	 * the bundled document, the one document accepted without isUsableDocument(); the
	 * degradation is reported by resolveDocument().
	 *
	 * @param array<string, mixed> $document
	 * @return array<string, ActivationPath>
	 */
	private function parsePaths(array $document): array
	{
		$paths = [];

		foreach (self::readSection($document, 'paths') as $pathKey => $node)
		{
			$code = (string)$pathKey;
			$parsedSteps = self::parsePathSteps($document, $code);
			if ($parsedSteps === [] || !is_array($node))
			{
				continue;
			}

			$rules = [];
			foreach ($parsedSteps as $parsedStep)
			{
				$rules[] = new PushRule($parsedStep['step'], $parsedStep['pushType']);
			}

			$paths[$code] = new ActivationPath(
				$code,
				array_column($parsedSteps, 'step'),
				$rules,
				$this->parseToolCodes($node),
				(int)($node['priority'] ?? 0),
			);
		}

		return $paths;
	}

	/**
	 * @param array<string, mixed> $pathNode
	 * @return string[]
	 */
	private function parseToolCodes(array $pathNode): array
	{
		$tools = $pathNode['tools'] ?? null;

		return is_array($tools) ? array_values(array_filter($tools, 'is_string')) : [];
	}

	/**
	 * Ordered steps of a path, keeping only the ones resolvable against the code.
	 *
	 * @param array<string, mixed> $document
	 * @return array<int, array{step: ActivationStep, pushType: PushType}>
	 */
	private static function parsePathSteps(array $document, string $pathCode): array
	{
		$stepIds = $document['paths'][$pathCode]['steps'] ?? null;
		if (!is_array($stepIds))
		{
			return [];
		}

		$parsedSteps = [];
		foreach ($stepIds as $stepId)
		{
			if (!is_string($stepId))
			{
				continue;
			}

			$step = ActivationStep::tryFrom($stepId);
			$pushType = self::parsePushType($document, $stepId);
			if ($step === null || $pushType === null)
			{
				continue;
			}

			$parsedSteps[] = [
				'step' => $step,
				'pushType' => $pushType,
			];
		}

		return $parsedSteps;
	}

	/**
	 * Push type of a step. The document token is not a PushType value: token 'web'
	 * means the web-login push DEMO_WEB (see PushType::getConfigToken).
	 *
	 * @param array<string, mixed> $document
	 */
	private static function parsePushType(array $document, string $stepId): ?PushType
	{
		$token = $document['steps'][$stepId]['pushType'] ?? null;

		return is_string($token) ? PushType::tryFromConfigToken($token) : null;
	}

	/**
	 * Step ids a path declares that the code cannot resolve: an unknown step id, an
	 * unknown push type token or an entry that is not a string at all.
	 *
	 * @param array<string, mixed> $document
	 * @return string[]
	 */
	private static function findUnresolvableStepIds(array $document, string $pathCode): array
	{
		$stepIds = $document['paths'][$pathCode]['steps'] ?? null;
		if (!is_array($stepIds))
		{
			return [];
		}

		$unresolvable = [];

		foreach ($stepIds as $stepId)
		{
			if (!is_string($stepId))
			{
				$unresolvable[] = '(not a string)';
			}
			elseif (ActivationStep::tryFrom($stepId) === null || self::parsePushType($document, $stepId) === null)
			{
				$unresolvable[] = $stepId;
			}
		}

		return $unresolvable;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getDocument(): array
	{
		$this->document ??= $this->resolveDocument();

		return $this->document;
	}

	/**
	 * The cached document when the reader can accept it, the bundled one otherwise. Every
	 * degradation is reported once: the provider lives for the whole pass, so a broken
	 * document costs one log line per pass instead of one per user.
	 *
	 * An empty cache is the shipped state - the remote service is not published yet - and
	 * says nothing worth logging.
	 *
	 * @return array<string, mixed>
	 */
	private function resolveDocument(): array
	{
		$raw = $this->getRawDocument();
		$cached = $raw === '' ? null : $this->decodeDocument($raw);
		if ($cached !== null && self::isUsableDocument($cached))
		{
			return $cached;
		}

		$bundled = $this->getBundledDocument();

		if ($raw !== '')
		{
			$flaws = $cached === null ? ['it is not a JSON object'] : self::findDocumentFlaws($cached);
			// the line says what the portal ended up running, not only what was refused: without
			// the manifest it degraded to, the reader cannot tell which campaign is live
			$this->logDegradation(
				'cached document rejected, running on the bundled '
				. self::describeManifest(is_array($bundled['meta'] ?? null) ? $bundled['meta'] : [])
				. ': ' . implode('; ', $flaws),
			);
		}

		$bundledFlaws = self::findDocumentFlaws($bundled);
		if ($bundledFlaws !== [])
		{
			$this->logDegradation('bundled document is incomplete: ' . implode('; ', $bundledFlaws));
		}

		return $bundled;
	}

	/**
	 * A document is usable only when every path it declares resolves as authored: the
	 * default path - the one every empty or unknown tool code lands on - must resolve at
	 * all, and no path may declare a step the code cannot resolve. A document that renamed
	 * a step id would otherwise be accepted and quietly move the portals of that tool onto
	 * a shorter path; rejecting it whole leaves them on the previous document instead.
	 *
	 * Public so that whoever fills the cache (RemoteCampaignConfigFetcher) rejects by the
	 * very criterion the reader applies, instead of caching a document that will silently
	 * degrade to the code default.
	 *
	 * @param array<string, mixed> $document
	 */
	public static function isUsableDocument(array $document): bool
	{
		return self::findDocumentFlaws($document) === [];
	}

	/**
	 * Why the document cannot be accepted, empty for a usable one. Phrased for the log:
	 * whoever degrades to another document says which path and which step made it.
	 *
	 * @param array<string, mixed> $document
	 * @return string[]
	 */
	private static function findDocumentFlaws(array $document): array
	{
		$flaws = [];

		if (self::parsePathSteps($document, self::DEFAULT_PATH_CODE) === [])
		{
			$flaws[] = "path '" . self::DEFAULT_PATH_CODE . "' resolves no step";
		}

		$paths = $document['paths'] ?? null;

		foreach (is_array($paths) ? $paths : [] as $pathKey => $node)
		{
			$code = (string)$pathKey;
			$declaredSteps = is_array($node) ? ($node['steps'] ?? null) : null;
			if (!is_array($declaredSteps) || $declaredSteps === [])
			{
				$flaws[] = "path '{$code}' declares no steps";

				continue;
			}

			$unresolvable = self::findUnresolvableStepIds($document, $code);
			if ($unresolvable !== [])
			{
				$flaws[] = "path '{$code}' declares unresolvable steps: " . implode(', ', $unresolvable);
			}

			$flaws = array_merge($flaws, self::findPathTypeFlaws($code, $node));
		}

		return array_merge(
			$flaws,
			self::findMetaFlaws($document),
			self::findScheduleFlaws($document),
			self::findStepFlaws($document),
			self::findFinalFlaws($document),
		);
	}

	/**
	 * The action a push opens stays in code (ActionTypeProvider) and the document carries it
	 * for tooling only - which is exactly why the two may not disagree. A document declaring a
	 * screen the code never sends is a promise nobody keeps, and it keeps silently: the shipped
	 * document asked for a task create form, the tap opened the task list, and neither half said
	 * a word about it.
	 *
	 * Accepted is either action the code may send for that push type - the tab one or its
	 * More-menu degradation - and no declaration at all: the field stays documentation, not a
	 * requirement. What it may not be is a third thing.
	 *
	 * @param mixed $node a single node of the `steps` section
	 * @return string[]
	 */
	private static function findDeeplinkFlaws(string $stepId, mixed $node): array
	{
		$declared = is_array($node) ? ($node['deeplink']['action'] ?? null) : null;
		if (!is_string($declared) || $declared === '')
		{
			return [];
		}

		$pushType = is_array($node) ? ($node['pushType'] ?? null) : null;
		$pushType = is_string($pushType) ? PushType::tryFromConfigToken($pushType) : null;
		if ($pushType === null)
		{
			// the unknown push type is reported by the path check; nothing to compare against
			return [];
		}

		$deliverable = $pushType->getCampaignActionTypes();

		if (in_array($declared, $deliverable, true))
		{
			return [];
		}

		return [
			"step '{$stepId}' declares a deeplink action the code never sends: '{$declared}'"
			. ' (deliverable: ' . implode(', ', $deliverable) . ')',
		];
	}

	/**
	 * `meta` carries the switch of the campaign and the region it was authored for, and both
	 * are read without a second opinion: RegionConfig::isSupported() casts `enabled` to bool,
	 * and the document is applied to whichever region reads the option. So a document that
	 * merely omits `enabled` switches the campaign off on every portal, the string "false"
	 * switches it on, and a document authored elsewhere brings its own schedule and texts to
	 * these portals. None of it shows in the structure of the document, and all of it has to
	 * be caught here, where a rejection keeps the portals on what they already run.
	 *
	 * A campaign switched off by its own author is not a flaw: `enabled: false` is accepted
	 * and cached, otherwise the switch would never reach the portals.
	 *
	 * @param array<string, mixed> $document
	 * @return string[]
	 */
	private static function findMetaFlaws(array $document): array
	{
		$meta = is_array($document['meta'] ?? null) ? $document['meta'] : [];
		$flaws = [];

		if (!is_bool($meta['enabled'] ?? null))
		{
			$flaws[] = 'meta declares no boolean `enabled`, and the campaign switch cannot be guessed';
		}

		if (($meta['region'] ?? null) !== self::CAMPAIGN_REGION)
		{
			$flaws[] = "meta declares a region other than '" . self::CAMPAIGN_REGION . "'";
		}

		return array_merge($flaws, self::findVersionFlaws($meta));
	}

	/**
	 * The two versions of a manifest, which are not one value: the format it is written in and
	 * the revision of the campaign it publishes.
	 *
	 * Both are required. An unreadable format version leaves the engine guessing which contract
	 * it is honouring, and an unreadable revision cannot be compared with the one already
	 * applied - the monotonicity that keeps a rollback from passing for an update is judged on
	 * that comparison alone (RemoteCampaignConfigFetcher).
	 *
	 * @param array<string, mixed> $meta
	 * @return string[]
	 */
	private static function findVersionFlaws(array $meta): array
	{
		$flaws = [];
		$schemaVersion = $meta['schemaVersion'] ?? null;

		if (!is_int($schemaVersion))
		{
			$flaws[] = 'meta declares `schemaVersion` as ' . get_debug_type($schemaVersion)
				. ', expected a whole format version'
			;
		}
		elseif ($schemaVersion < self::MIN_SCHEMA_VERSION || $schemaVersion > self::MAX_SCHEMA_VERSION)
		{
			$flaws[] = 'meta declares schemaVersion ' . $schemaVersion
				. ' outside the range this engine implements: '
				. self::MIN_SCHEMA_VERSION . ' to ' . self::MAX_SCHEMA_VERSION
			;
		}

		if (self::readRevision($meta) === null)
		{
			$flaws[] = 'meta declares no readable `version`, and the revision of the campaign'
				. ' cannot be compared with the applied one'
			;
		}

		return $flaws;
	}

	/**
	 * Revision of the campaign a `meta` section publishes, null when it declares none.
	 *
	 * @param array<string, mixed> $meta
	 */
	public static function readRevision(array $meta): ?string
	{
		$revision = $meta['version'] ?? null;

		return is_string($revision) && $revision !== '' ? $revision : null;
	}

	/**
	 * The manifest named for the log (AC-032): the format the engine read it as and the
	 * revision of the campaign it publishes. Whoever reads a line about the campaign has to be
	 * able to tell which manifest the portal is on.
	 *
	 * @param array<string, mixed> $meta
	 */
	public static function describeManifest(array $meta): string
	{
		return 'manifest schemaVersion ' . var_export($meta['schemaVersion'] ?? null, true)
			. ', revision ' . (self::readRevision($meta) ?? 'unknown')
		;
	}

	/**
	 * The manifest the portal runs on right now, named for the log - the cached one when the
	 * reader accepts it, the bundled one otherwise.
	 */
	public function describeAppliedManifest(): string
	{
		return self::describeManifest($this->getMeta());
	}

	/**
	 * Revision of the campaign the portal runs on right now. Whoever fills the cache compares
	 * against it: the applied manifest is the floor a published one has to clear.
	 */
	public function getAppliedRevision(): ?string
	{
		return self::readRevision($this->getMeta());
	}

	/**
	 * The schedule the pipeline would actually run on. ScheduleConfig fails closed, so a
	 * document without a usable schedule is structurally fine and sends nothing at all -
	 * no send day, a closed window, an age gate no portal satisfies. Rejecting it keeps
	 * the portals on the document they already run instead of stopping them silently.
	 *
	 * @param array<string, mixed> $document
	 * @return string[]
	 */
	private static function findScheduleFlaws(array $document): array
	{
		$raw = is_array($document['schedule'] ?? null) ? $document['schedule'] : [];
		$schedule = ScheduleConfig::fromSchedule($raw);

		$flaws = self::findScheduleTypeFlaws($raw);

		if ($schedule->sendDayOffsets === [])
		{
			$flaws[] = 'schedule declares no send day';
		}

		if ($schedule->sendWindowHours <= 0)
		{
			$flaws[] = 'schedule declares no send window';
		}
		elseif (SendWindow::tryFrom($schedule->sendWindowStart, $schedule->sendWindowHours) === null)
		{
			// a positive length says nothing about the rest: a start nobody can read, or a window
			// running past midnight, closes the window for good - SendWindow declines both, and
			// the send condition then refuses every pass of the campaign
			$flaws[] = "schedule declares an unusable send window: "
				. "'{$schedule->sendWindowStart}' for {$schedule->sendWindowHours} h"
			;
		}

		if ($schedule->maxPortalAgeDays < 0)
		{
			$flaws[] = 'schedule declares no portal age limit';
		}

		return array_merge($flaws, self::findRepeatFlaws($raw['repeat'] ?? null));
	}

	/**
	 * The rhythm of the series (`schedule.repeat`): the send days between the first push and
	 * the second, and between every pair after it.
	 *
	 * It is required, and it is judged as published. The series has no calendar of its own -
	 * every send is counted from the push that actually went out before it - so a document
	 * without a readable rhythm declares no cadence at all, while SeriesRhythm carries
	 * defaults for the engine to start from. Accepting such a document would run every portal
	 * on a cadence nobody authored.
	 *
	 * @return string[]
	 */
	private static function findRepeatFlaws(mixed $node): array
	{
		if ($node === null)
		{
			return ['schedule declares no repeat rhythm for the series'];
		}

		if (!is_array($node))
		{
			return [self::describeScheduleType('repeat', $node, 'an object')];
		}

		$flaws = [];

		foreach (self::REPEAT_INTERVAL_KEYS as $key)
		{
			$interval = $node[$key] ?? null;
			if (!is_int($interval))
			{
				$flaws[] = self::describeScheduleType(
					"repeat.{$key}",
					$interval,
					'a whole number of send days',
				);

				continue;
			}

			if ($interval < 1 || $interval > SeriesRhythm::MAX_INTERVAL_WORKDAYS)
			{
				$flaws[] = "schedule declares a repeat.{$key} outside the range the series walks: "
					. $interval . ' of 1 to ' . SeriesRhythm::MAX_INTERVAL_WORKDAYS
				;
			}
		}

		return $flaws;
	}

	/**
	 * The schedule as it was published, before ScheduleConfig makes it usable. That parsing
	 * coerces every value, on purpose: a document which reached the run time has to yield a
	 * schedule rather than an exception. Which makes the parsed object the wrong thing to judge
	 * a document by - the string "false" arrives as true, a timezone the engine does not
	 * implement as the server one, "2.9" hours as 2 - and every check below would then be
	 * reading a schedule nobody published.
	 *
	 * So the types are judged here, where a rejection keeps the portals on the document they
	 * already run, instead of switching them to a coerced reading of a new one.
	 *
	 * @param array<string, mixed> $schedule the raw `schedule` section
	 * @return string[]
	 */
	private static function findScheduleTypeFlaws(array $schedule): array
	{
		$flaws = [];

		foreach (self::SCHEDULE_FLAGS as $flag)
		{
			if (array_key_exists($flag, $schedule) && !is_bool($schedule[$flag]))
			{
				$flaws[] = self::describeScheduleType($flag, $schedule[$flag], 'a boolean');
			}
		}

		if (array_key_exists('maxPortalAgeDays', $schedule) && !is_int($schedule['maxPortalAgeDays']))
		{
			$flaws[] = self::describeScheduleType(
				'maxPortalAgeDays',
				$schedule['maxPortalAgeDays'],
				'a whole number of days',
			);
		}

		return array_merge(
			$flaws,
			self::findSendWindowTypeFlaws($schedule['sendWindow'] ?? null),
			self::findSendDayOffsetFlaws($schedule['sendDayOffsets'] ?? null),
		);
	}

	/**
	 * @return string[]
	 */
	private static function findSendWindowTypeFlaws(mixed $window): array
	{
		if ($window === null)
		{
			return [];
		}

		if (!is_array($window))
		{
			return [self::describeScheduleType('sendWindow', $window, 'an object')];
		}

		$flaws = [];

		if (array_key_exists('start', $window) && !is_string($window['start']))
		{
			$flaws[] = self::describeScheduleType('sendWindow.start', $window['start'], "a time like '10:00'");
		}

		if (array_key_exists('windowHours', $window) && !is_int($window['windowHours']))
		{
			$flaws[] = self::describeScheduleType(
				'sendWindow.windowHours',
				$window['windowHours'],
				'a whole number of hours',
			);
		}

		$timezone = $window['timezone'] ?? null;
		if ($timezone !== null && !in_array($timezone, ScheduleConfig::TIMEZONES, true))
		{
			$flaws[] = self::describeScheduleType(
				'sendWindow.timezone',
				$timezone,
				'one of ' . implode(', ', ScheduleConfig::TIMEZONES),
			);
		}

		return $flaws;
	}

	/**
	 * The send days, judged as published as well: parsing drops what it cannot read, clamps
	 * what is out of range and keeps the first MAX_SEND_DAYS of the rest, so a document asking
	 * for a day the engine will not walk is accepted and simply never sends it.
	 *
	 * @return string[]
	 */
	private static function findSendDayOffsetFlaws(mixed $offsets): array
	{
		if ($offsets === null)
		{
			return [];
		}

		if (!is_array($offsets) || !array_is_list($offsets))
		{
			return [self::describeScheduleType('sendDayOffsets', $offsets, 'a list of day numbers')];
		}

		$flaws = [];

		if (count($offsets) > ScheduleConfig::MAX_SEND_DAYS)
		{
			$flaws[] = 'schedule declares more sendDayOffsets than the series walks: '
				. count($offsets) . ' of at most ' . ScheduleConfig::MAX_SEND_DAYS
			;
		}

		$mistyped = array_values(array_filter($offsets, static fn (mixed $offset): bool => !is_int($offset)));
		if ($mistyped !== [])
		{
			$flaws[] = self::describeScheduleType('sendDayOffsets', $mistyped[0], 'whole day numbers');
		}

		$outOfRange = array_values(array_filter(
			$offsets,
			static fn (mixed $offset): bool => is_int($offset)
				&& ($offset < 0 || $offset > ScheduleConfig::MAX_OFFSET_DAYS),
		));
		if ($outOfRange !== [])
		{
			$flaws[] = 'schedule declares a sendDayOffsets day outside the range the engine walks: '
				. $outOfRange[0] . ' of 0 to ' . ScheduleConfig::MAX_OFFSET_DAYS
			;
		}

		return $flaws;
	}

	private static function describeScheduleType(string $key, mixed $value, string $expected): string
	{
		return "schedule declares '{$key}' as " . get_debug_type($value) . ", expected {$expected}";
	}

	/**
	 * Both values of a path node are read through a cast, so a mistyped one is not an error
	 * but a different campaign: `(int)'high'` is 0, which drops the path to the bottom of the
	 * priority order and hands a portal the series of another tool; a `tools` node that is not
	 * a list yields no codes at all, and the path then matches nobody while the document reads
	 * as healthy. Judged before the cast, the way the schedule section is.
	 *
	 * @param mixed $node a single node of the `paths` section
	 * @return string[]
	 */
	private static function findPathTypeFlaws(string $code, mixed $node): array
	{
		if (!is_array($node))
		{
			return [];
		}

		$flaws = [];

		if (array_key_exists('priority', $node) && !is_int($node['priority']))
		{
			$flaws[] = self::describePathType($code, 'priority', $node['priority'], 'an integer');
		}

		if (array_key_exists('tools', $node) && !(is_array($node['tools']) && array_is_list($node['tools'])))
		{
			$flaws[] = self::describePathType($code, 'tools', $node['tools'], 'a list of tool codes');
		}

		return $flaws;
	}

	private static function describePathType(string $code, string $key, mixed $value, string $expected): string
	{
		return "path '{$code}' declares '{$key}' as " . get_debug_type($value) . ", expected {$expected}";
	}

	/**
	 * Two things the steps section must hold beyond resolvable ids.
	 *
	 * A push type belongs to at most one step, because the settings of a step are looked
	 * up by push type (findStepByPushType): a second step carrying the same token makes
	 * the lookup answer with whichever of them the document happens to list first, and the
	 * attempt limit and the analytics code then come from the wrong step.
	 *
	 * A step some path declares needs texts, or its day of the series reports empty
	 * content instead of sending - every attempt, for every portal on that path.
	 *
	 * @param array<string, mixed> $document
	 * @return string[]
	 */
	private static function findStepFlaws(array $document): array
	{
		$flaws = [];

		$stepIdsByPushType = [];
		$attemptsByPushType = [];
		foreach (self::readSection($document, 'steps') as $stepId => $node)
		{
			$pushType = is_array($node) ? ($node['pushType'] ?? null) : null;
			if (is_string($pushType) && $pushType !== '')
			{
				$stepIdsByPushType[$pushType][] = (string)$stepId;
				$attemptsByPushType[$pushType] = StepConfig::fromStepNode($node)->maxAttempts;
			}

			$flaws = array_merge(
				$flaws,
				self::findDeeplinkFlaws((string)$stepId, $node),
				self::findGateFlaws((string)$stepId, $node),
			);
		}

		foreach ($stepIdsByPushType as $pushType => $stepIds)
		{
			if (count($stepIds) > 1)
			{
				$flaws[] = "push type '{$pushType}' is declared by several steps: " . implode(', ', $stepIds);
			}
		}

		$content = self::readSection($document, 'content');
		foreach (self::findUsedPushTypes($document) as $pushType)
		{
			$texts = $content[$pushType] ?? null;
			if (!is_array($texts) || $texts === [])
			{
				$flaws[] = "push type '{$pushType}' has no texts";

				continue;
			}

			if (self::resolveIndustryBucket($document, $texts, null) === [])
			{
				$flaws[] = "push type '{$pushType}' has no texts for an industry nobody authored";
			}

			$uncovered = self::findUncoveredAttempts($texts, $attemptsByPushType[$pushType] ?? StepConfig::DEFAULT_MAX_ATTEMPTS);
			if ($uncovered !== [])
			{
				$flaws[] = "push type '{$pushType}' has no usable text for attempt " . implode(', ', $uncovered);
			}
		}

		return $flaws;
	}

	/**
	 * A gate the pipeline can act on. Only the check of the message step takes one, and
	 * StepCheckerRegistry asks the document for the condition of that step alone - so a gate
	 * written on any other step is a condition the campaign reads and never applies, and the
	 * step it guards is credited by its bare fact instead.
	 *
	 * @param mixed $node the raw step node
	 * @return string[]
	 */
	private static function findGateFlaws(string $stepId, mixed $node): array
	{
		$gate = is_array($node) ? ($node['gate'] ?? null) : null;
		if ($gate === null || $gate === '' || $stepId === ActivationStep::MESSAGE->value)
		{
			return [];
		}

		return [
			"step '{$stepId}' declares a gate, which only the step '"
			. ActivationStep::MESSAGE->value . "' can carry",
		];
	}

	/**
	 * Attempts the texts of a push type leave without a text. A bucket may either name every
	 * attempt it is allowed or carry the ANY_ATTEMPT wildcard, which answers for all of them;
	 * anything in between stalls the step on the first attempt nobody authored - and it stalls
	 * it days into the series, when the document has long been accepted.
	 *
	 * The check is per industry bucket, because that is the granularity the text is read at.
	 *
	 * @param array<string, mixed> $byIndustry the `content` node of one push type
	 * @return string[] labels of the uncovered attempts, "<industry>:<attempt>"
	 */
	private static function findUncoveredAttempts(array $byIndustry, int $maxAttempts): array
	{
		$uncovered = [];
		foreach ($byIndustry as $industry => $bucket)
		{
			$bucket = is_array($bucket) ? $bucket : [];

			for ($attempt = 1; $attempt <= $maxAttempts; $attempt++)
			{
				// the fallback of the reader, key by key: `??` gives way only to a MISSING key, so
				// a leaf that is present and unusable shadows the wildcard instead of yielding to it
				$leaf = $bucket[(string)$attempt] ?? $bucket[self::ANY_ATTEMPT] ?? null;
				if (!self::isUsableContentLeaf($leaf))
				{
					$uncovered[] = "{$industry}:{$attempt}";
				}
			}
		}

		return $uncovered;
	}

	/**
	 * Whether a leaf is one the read path would actually hand to a push: both texts present,
	 * neither empty, both within the length limit - pickContentLeaf plus the emptiness gate of
	 * PushContentProvider, which is where an empty title stops being content.
	 *
	 * A leaf failing this is not coverage. The day it belongs to reports empty content instead of
	 * sending, on every attempt, and the step never advances - days into a series that was
	 * accepted as complete.
	 */
	private static function isUsableContentLeaf(mixed $leaf): bool
	{
		if (!is_array($leaf) || !is_string($leaf['title'] ?? null) || !is_string($leaf['body'] ?? null))
		{
			return false;
		}

		$title = $leaf['title'];
		$body = $leaf['body'];

		return $title !== ''
			&& $body !== ''
			&& self::isWithinLengthLimit($title)
			&& self::isWithinLengthLimit($body)
		;
	}

	/**
	 * A final the document declares has to be one the engine can send: a key the code knows, an
	 * offset and condition FinalPushConfig accepts, and texts the reader would hand to a push.
	 *
	 * The section itself is optional - a campaign may end with the series - but a final declared
	 * badly is worse than an absent one. FinalPushConfig fails closed, so an unreadable offset or
	 * a condition the code does not implement simply yields null, and a null final is never sent:
	 * the campaign goes quiet one push early and nothing says why.
	 *
	 * @param array<string, mixed> $document
	 * @return string[]
	 */
	private static function findFinalFlaws(array $document): array
	{
		$finals = self::readSection($document, 'finals');
		if ($finals === [])
		{
			return [];
		}

		$flaws = [];
		$declared = [];

		foreach ([PushType::MEETUP, PushType::IMPLEMENTATION_HELP] as $pushType)
		{
			$key = $pushType->getFinalKey();
			if ($key === null || !isset($finals[$key]))
			{
				continue;
			}

			$declared[] = $key;

			if (FinalPushConfig::fromFinals($finals, $pushType) === null)
			{
				$flaws[] = "final '{$key}' declares no usable offset or condition";

				continue;
			}

			$flaws = array_merge(
				$flaws,
				self::findFinalRepeatFlaws($key, $finals[$key]),
				self::findFinalAnalyticsFlaws($document, $key, $finals[$key]),
				self::findFinalTargetFlaws($key, $finals[$key]),
				self::findFinalContentFlaws($document, $key, $finals[$key]),
			);
		}

		foreach (array_diff(array_keys($finals), $declared) as $unknown)
		{
			$flaws[] = "final '{$unknown}' is not a final the code implements";
		}

		return $flaws;
	}

	/**
	 * Every final is sent at most once, and no code reads `sendOnce` to decide it. So the key
	 * can only confirm what the engine does: a final declaring anything else asks for a
	 * repetition that will never happen, and asks it where nobody looks.
	 *
	 * @param mixed $node the node of one final
	 * @return string[]
	 */
	private static function findFinalRepeatFlaws(string $key, mixed $node): array
	{
		$declared = is_array($node) ? $node : [];
		if (!array_key_exists('sendOnce', $declared) || $declared['sendOnce'] === true)
		{
			return [];
		}

		return [
			"final '{$key}' asks to repeat, which the engine does not do:"
			. ' sendOnce must be true when declared',
		];
	}

	/**
	 * A document naming a series owns both halves of the funnel of every push it sends, and a
	 * final naming neither is still sent: the send falls back to the legacy `push_mobile_1-8d_*`
	 * code of the push type, and the client does the same for the click. The final is then
	 * counted in the previous campaign while this document claims it.
	 *
	 * A document without a series is reported under those legacy codes throughout, so its
	 * finals have nothing to be missing from.
	 *
	 * @param array<string, mixed> $document
	 * @param mixed $node the node of one final
	 * @return string[]
	 */
	private static function findFinalAnalyticsFlaws(array $document, string $key, mixed $node): array
	{
		$series = self::readSection($document, 'analytics')['series'] ?? null;
		if (!is_string($series) || $series === '')
		{
			return [];
		}

		$flaws = [];

		foreach (self::ANALYTICS_HALVES as $half)
		{
			$code = is_array($node) ? ($node['analytics'][$half] ?? null) : null;
			if (!is_string($code) || $code === '')
			{
				$flaws[] = "final '{$key}' has no analytics.{$half} code,"
					. ' and the legacy series code would be reported instead'
				;
			}
		}

		return $flaws;
	}

	/**
	 * The card a final aims at travels to the device as it is, so an unfilled placeholder ships
	 * as a real target: the tap asks for a card called `<banner-help>`, nothing matches it, and
	 * the section opens with no highlight - indistinguishable from a final that names no card at
	 * all. The shipped document carried exactly such a pair, which is why the shape is refused
	 * rather than trusted to be noticed.
	 *
	 * @return string[]
	 */
	private static function findFinalTargetFlaws(string $key, mixed $node): array
	{
		$target = is_array($node) ? ($node['deeplink']['menuItemId'] ?? null) : null;
		if (!is_string($target) || !preg_match('/[<>]/', $target))
		{
			return [];
		}

		return ["final '{$key}' aims at a placeholder rather than a card: '{$target}'"];
	}

	/**
	 * Texts of one final. It authors a single variant per industry under the wildcard attempt
	 * key, which is how getFinalContent() reads it.
	 *
	 * @param array<string, mixed> $document
	 * @param mixed $node the node of one final
	 * @return string[]
	 */
	private static function findFinalContentFlaws(array $document, string $key, mixed $node): array
	{
		$byIndustry = is_array($node) ? ($node['content'] ?? null) : null;
		if (!is_array($byIndustry) || $byIndustry === [])
		{
			return ["final '{$key}' has no texts"];
		}

		$flaws = [];

		if (self::resolveIndustryBucket($document, $byIndustry, null) === [])
		{
			$flaws[] = "final '{$key}' has no texts for an industry nobody authored";
		}

		foreach ($byIndustry as $industry => $bucket)
		{
			$leaf = is_array($bucket) ? ($bucket[self::ANY_ATTEMPT] ?? null) : null;
			if (!self::isUsableContentLeaf($leaf))
			{
				$flaws[] = "final '{$key}' has no usable text for industry '{$industry}'";
			}
		}

		return $flaws;
	}

	/**
	 * Push type tokens of the steps the declared paths walk. Steps nobody declares are not
	 * the reader's business - the document may keep a step it does not use yet.
	 *
	 * @param array<string, mixed> $document
	 * @return string[]
	 */
	private static function findUsedPushTypes(array $document): array
	{
		$steps = self::readSection($document, 'steps');

		$pushTypes = [];
		foreach (self::readSection($document, 'paths') as $node)
		{
			$declaredSteps = is_array($node) ? ($node['steps'] ?? null) : null;
			foreach (is_array($declaredSteps) ? $declaredSteps : [] as $stepId)
			{
				$pushType = $steps[(string)$stepId]['pushType'] ?? null;
				if (is_string($pushType) && $pushType !== '')
				{
					$pushTypes[$pushType] = $pushType;
				}
			}
		}

		return array_values($pushTypes);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function decodeDocument(string $raw): ?array
	{
		if ($raw === '')
		{
			return null;
		}

		try
		{
			$decoded = Json::decode($raw);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getSection(string $name): array
	{
		return self::readSection($this->getDocument(), $name);
	}

	/**
	 * @param array<string, mixed> $document
	 * @return array<string, mixed>
	 */
	private static function readSection(array $document, string $name): array
	{
		$section = $document[$name] ?? null;

		return is_array($section) ? $section : [];
	}

	protected function getRawDocument(): string
	{
		return (string)Option::get(self::MODULE_ID, self::OPTION_NAME, '');
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function getBundledDocument(): array
	{
		return DefaultCampaignConfig::get();
	}

	protected function logDegradation(string $reason): void
	{
		AddMessage2Log('Onboarding campaign config: ' . $reason, self::MODULE_ID);
	}
}
