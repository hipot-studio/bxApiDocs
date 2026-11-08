<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service;

use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Socialnetwork\Collab\Analytics\CollabAnalytics;
use Bitrix\Socialnetwork\Helper\Analytics\Analytics;
use Bitrix\Socialnetwork\Helper\Feature;
use Bitrix\Socialnetwork\Item\Workgroup\Type;

class ProjectAnalytics extends Analytics
{
	// Origin values for p3 in member events; must contain at most one underscore (AnalyticsEvent::validatePField).
	public const ORIGIN_PROJECT = 'origin_project';

	// open_section is sent only for links we marked ourselves, otherwise any bookmark would produce an event.
	// ELEMENT_FILES_BUTTON is inherited from Helper\Analytics\Analytics.
	private const OPEN_FILES_SECTION_ELEMENTS = [self::ELEMENT_FILES_BUTTON];

	private static array $hydrationCache = [];

	public function __construct(
		private readonly CollabAnalytics $collabAnalytics,
	)
	{
	}

	public function getHydration(int $userId): array
	{
		if (!array_key_exists($userId, self::$hydrationCache))
		{
			self::$hydrationCache[$userId] = [
				'userType' => $this->collabAnalytics->getUserTypeParameter($userId),
				'isDemo' => Feature::isFeatureEnabledByTrial(Feature::PROJECTS_GROUPS) ? 'Y' : 'N',
			];
		}

		return self::$hydrationCache[$userId];
	}

	/**
	 * Reads the c_element marker from the current request and defers the event to a background job: the marker must
	 * be read in request context, while the actual send() runs after the response. Callers only pass scalars into
	 * the closure so nothing request-bound is captured.
	 */
	public function trackOpenFilesSectionFromRequest(int $groupId, int $userId): void
	{
		if ($groupId <= 0)
		{
			return;
		}

		$element = Context::getCurrent()->getRequest()->getQuery('c_element');
		if (!is_string($element) || $element === '')
		{
			return;
		}

		Application::getInstance()->addBackgroundJob(
			fn () => $this->trackOpenFilesSection($groupId, $userId, $element),
		);
	}

	/**
	 * Server-side twin of SectionAnalytics.trackOpen() from socialnetwork.v2.lib.event-sender: both paths must
	 * produce the same event, so the field set is kept in sync with the JS one.
	 */
	public function trackOpenFilesSection(int $groupId, int $userId, string $element): void
	{
		if (!in_array($element, self::OPEN_FILES_SECTION_ELEMENTS, true))
		{
			return;
		}

		$hydration = $this->getHydration($userId);

		$event = new AnalyticsEvent('open_section', 'files', 'files_operations');
		$event
			->setSection('project')
			->setElement($element)
			->setStatus(self::STATUS_SUCCESS)
			->setP1('isDemo_' . $hydration['isDemo'])
			->setP2($hydration['userType'])
			->setP4("projectId_{$groupId}")
			->send()
		;
	}

	public function trackProjectsView(): void
	{
		$event = new AnalyticsEvent('projects_view', 'projects', 'project');

		$this->sendAnalytics(
			analyticsEvent: $event,
			section: 'project',
			params: [
				'p1' => $this->getIsDemoParam(),
			],
		);
	}

	public function trackScrumView(): void
	{
		$event = new AnalyticsEvent('scrum_view', 'tasks', 'scrum');

		$this->sendAnalytics(
			analyticsEvent: $event,
			section: 'scrum',
			element: 'section_button',
			subSection: 'scrum_grid',
			params: [
				'p1' => $this->getIsDemoParam(Feature::SCRUM_CREATE),
			],
		);
	}

	public function trackConvertStart(
		int $groupId,
		Type $sourceType,
		int $userId,
		?bool $isOpened = null,
	): void
	{
		$this->sendConvertEvent('project_convert_start', $groupId, $sourceType, $userId, $isOpened, null);
	}

	public function trackConvertFinish(
		int $groupId,
		Type $sourceType,
		int $userId,
		?bool $isOpened = null,
		?string $errorCode = null,
	): void
	{
		$this->sendConvertEvent('project_convert_finish', $groupId, $sourceType, $userId, $isOpened, $errorCode);
	}

	public function trackSetProjectName(int $groupId, string $name, bool $isOpened): void
	{
		$analyticsEvent = $this->buildBaseEvent('set_project_name', $groupId);
		$analyticsEvent->setP1('name_' . $this->transliterate($name));
		$analyticsEvent->setP3($this->resolveProjectType($isOpened));

		$analyticsEvent->send();
	}

	public function trackProjectCopyFinish(
		int $newGroupId,
		int $sourceGroupId,
		int $userId,
		?array $copyOptions,
		?bool $isOpened = null,
		?string $errorCode = null,
	): void
	{
		// p4 = id of the new (copied) project, p5 = source project
		$analyticsEvent = $this->buildBaseEvent('project_copy_finish', $newGroupId);
		$analyticsEvent->setStatus($this->resolveStatus($errorCode));
		$analyticsEvent->setP1($this->formatCopyOptions($copyOptions));
		$analyticsEvent->setP2($this->resolveUserType($userId));
		$analyticsEvent->setP5("initialProjectId_{$sourceGroupId}");

		if ($isOpened !== null)
		{
			$analyticsEvent->setP3($this->resolveProjectType($isOpened));
		}

		$analyticsEvent->send();
	}

	/**
	 * Server-side twin of ProjectAnalytics.trackCreateFinish() from socialnetwork.v2.lib.event-sender.
	 * Collab creation bypasses the project wizard (it goes through the im module), so the wizard never
	 * emits project_create_finish for a collab. A collab is a project, hence the event must fire here too.
	 * $userId is the creator, used for p2 (userType).
	 */
	public function trackProjectCreateFinish(int $groupId, int $userId, string $status, array $properties): void
	{
		$analyticsEvent = $this->buildBaseEvent('project_create_finish', $groupId);
		$analyticsEvent->setStatus($status);
		$analyticsEvent->setP2($this->resolveUserType($userId));
		$analyticsEvent->setP5($this->formatProjectProperties($properties));

		$analyticsEvent->send();
	}

	public function trackEditSettings(
		int $groupId,
		int $userId,
		bool $wasListsActive,
		bool $isListsActive,
		bool $isOpened,
		?string $errorCode = null,
	): void
	{
		$this->buildEditSettingsEvent(
			$groupId,
			$userId,
			$wasListsActive,
			$isListsActive,
			$isOpened,
			$errorCode,
		)->send();
	}

	public function trackAddProjectUser(int $groupId, int $userId, ?string $origin = null): void
	{
		$this->sendMemberEvent('add_project_user', $groupId, $userId, $origin);
	}

	public function trackDeleteProjectUser(int $groupId, int $userId, ?string $origin = null): void
	{
		$this->sendMemberEvent('delete_project_user', $groupId, $userId, $origin);
	}

	public function trackAddDepartmentToProject(int $groupId, int $userId, ?string $origin = null): void
	{
		$this->sendMemberEvent('add_department_to_project', $groupId, $userId, $origin);
	}

	public function trackDeleteDepartmentFromProject(int $groupId, int $userId, ?string $origin = null): void
	{
		$this->sendMemberEvent('delete_department_from_project', $groupId, $userId, $origin);
	}

	private function sendMemberEvent(string $event, int $groupId, int $userId, ?string $origin = null): void
	{
		$analyticsEvent = $this->buildBaseEvent($event, $groupId);
		$analyticsEvent->setStatus(self::STATUS_SUCCESS);
		$analyticsEvent->setP2($this->resolveUserType($userId));

		if ($origin !== null)
		{
			$analyticsEvent->setP3($origin);
		}

		$analyticsEvent->send();
	}

	private function sendConvertEvent(
		string $event,
		int $groupId,
		Type $sourceType,
		int $userId,
		?bool $isOpened,
		?string $errorCode,
	): void
	{
		$analyticsEvent = $this->buildBaseEvent($event, $groupId);
		$analyticsEvent->setType($sourceType === Type::Collab ? 'collab' : 'project');
		$analyticsEvent->setStatus($this->resolveStatus($errorCode));
		$analyticsEvent->setP2($this->resolveUserType($userId));

		if ($isOpened !== null)
		{
			$analyticsEvent->setP3($this->resolveProjectType($isOpened));
		}

		$analyticsEvent->send();
	}

	private function buildEditSettingsEvent(
		int $groupId,
		int $userId,
		bool $wasListsActive,
		bool $isListsActive,
		bool $isOpened,
		?string $errorCode,
	): AnalyticsEvent
	{
		$analyticsEvent = new AnalyticsEvent('edit_settings', 'projects', 'project');
		$analyticsEvent->setSection('messenger');
		$analyticsEvent->setStatus($this->resolveStatus($errorCode));
		$prefix = $wasListsActive === $isListsActive ? 'list_' : 'switched_';
		$analyticsEvent->setP1($prefix . (int)$isListsActive);
		$analyticsEvent->setP2($this->resolveUserType($userId));
		$analyticsEvent->setP3($this->resolveProjectType($isOpened));
		$analyticsEvent->setP4("projectId_{$groupId}");

		return $analyticsEvent;
	}

	private function buildBaseEvent(string $event, int $groupId): AnalyticsEvent
	{
		$analyticsEvent = new AnalyticsEvent($event, 'projects', 'project');
		$analyticsEvent->setSection('project');
		$analyticsEvent->setP1($this->getIsDemoParam());
		$analyticsEvent->setP4("projectId_{$groupId}");

		return $analyticsEvent;
	}

	private function resolveUserType(int $userId): string
	{
		static $cache = [];

		if (!array_key_exists($userId, $cache))
		{
			$userType = $this->collabAnalytics->getUserTypeParameter($userId);
			$cache[$userId] = $userType === '' ? 'user_intranet' : $userType;
		}

		return $cache[$userId];
	}

	private function resolveProjectType(bool $isOpened): string
	{
		return 'projectType_' . ($isOpened ? 'open' : 'close');
	}

	/**
	 * Copy flags as JSON, mirroring project_create_finish's project_properties. A p-field value may contain at
	 * most one underscore (AnalyticsEvent::validatePField), otherwise the event is silently dropped in production —
	 * so every "_" in the serialized JSON is replaced with "-" (keys arrive kebab-case: copy-tasks, copy-robots, …).
	 *
	 * @param array{tasks?: array{enabled?: bool, robots?: bool}, disk?: array{enabled?: bool, withFiles?: bool}}|null $copyOptions
	 */
	private function formatCopyOptions(?array $copyOptions): string
	{
		$tasks = (array)($copyOptions['tasks'] ?? []);
		$disk = (array)($copyOptions['disk'] ?? []);

		$payload = [
			'copy_tasks' => $this->toYesNo($tasks['enabled'] ?? false),
			'copy_robots' => $this->toYesNo($tasks['robots'] ?? false),
			'copy_folders' => $this->toYesNo($disk['enabled'] ?? false),
			'copy_files' => $this->toYesNo($disk['withFiles'] ?? false),
		];

		return str_replace('_', '-', (string)\json_encode($payload));
	}

	/**
	 * Mirror of buildProjectProperties() in socialnetwork.v2.lib.event-sender: the key set, their order and
	 * value shapes (ints for flags/counts, strings for project_type/main_tool) must match the JS wizard so
	 * both create paths produce an identical project_create_finish p5. This method owns the canonical order,
	 * so callers may pass the associative array in any order. A p-field value may contain at most one
	 * underscore (AnalyticsEvent::validatePField), otherwise the event is silently dropped in production —
	 * so every "_" in the serialized JSON is replaced with "-" (leaving no underscore at all).
	 */
	private function formatProjectProperties(array $properties): string
	{
		$payload = [
			'owner_change' => (int)($properties['owner_change'] ?? 0),
			'moderators' => (int)($properties['moderators'] ?? 0),
			'participants' => (int)($properties['participants'] ?? 0),
			'departments' => (int)($properties['departments'] ?? 0),
			'project_type' => (string)($properties['project_type'] ?? ''),
			'main_tool' => (string)($properties['main_tool'] ?? ''),
			'access_rights_state' => (int)($properties['access_rights_state'] ?? 0),
			'additional_settings_calendar' => (int)($properties['additional_settings_calendar'] ?? 0),
			'additional_settings_auto_delete' => (int)($properties['additional_settings_auto_delete'] ?? 0),
		];

		return str_replace('_', '-', (string)\json_encode($payload));
	}

	private function toYesNo(mixed $value): string
	{
		return $value ? 'Y' : 'N';
	}

	private function transliterate(string $name): string
	{
		// 'ru' transliteration table: project names are predominantly Cyrillic; 'en' would drop them entirely.
		$value = \CUtil::translit($name, 'ru', [
			'max_len' => 100,
			'change_case' => 'L',
			'replace_space' => '-',
			'replace_other' => '-',
		]);

		// A p-field allows at most one underscore (AnalyticsEvent::validatePField); that single underscore is
		// the "name_" prefix separator, so the transliterated value itself must not contain any underscore —
		// otherwise the whole set_project_name event is silently dropped in production.
		return str_replace('_', '-', $value);
	}

	private function resolveStatus(?string $errorCode): string
	{
		if ($errorCode === null)
		{
			return self::STATUS_SUCCESS;
		}

		if ($errorCode === '' || $errorCode === '0')
		{
			return self::STATUS_ERROR;
		}

		return "error_{$errorCode}";
	}

	private function getIsDemoParam(string $feature = Feature::PROJECTS_GROUPS): string
	{
		return 'isDemo_' . (Feature::isFeatureEnabledByTrial($feature) ? 'Y' : 'N');
	}
}
