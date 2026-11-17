<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Error;
use Bitrix\Main\Errorable;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\Access\TemplateAccessController;
use Bitrix\Tasks\Feature\RegularTasksSectionFeature;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\Slider\Path\PathMaker;
use Bitrix\Tasks\Slider\Path\TemplatePathMaker;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\ReplicationGrid;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Settings\ReplicationGridSettings;
use Bitrix\Tasks\V2\Internal\Service\Replication\Schedule\PeriodColumns;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionSettingsService;

final class TasksReplicationComponent extends CBitrixComponent implements Controllerable, Errorable
{
	public const ERROR_FEATURE_DISABLED = 'REGULAR_TASKS_SECTION_DISABLED';

	/**
	 * Согласованный GRID_ID раздела (должен совпадать с backend ReplicationGridSettings).
	 * ВНИМАНИЕ (reconcile): значение фиксируется здесь на фронте; backend-трек обязан
	 * использовать то же самое (прецедент содержал расхождение — не копировать).
	 */
	public const GRID_ID = 'tasks-replication-grid';

	/**
	 * Ролевой срез раздела: roleId → фраза подписи (согласовано с RoleId/RoleLocMessage
	 * фронта). Ключи задают набор допустимых значений сохранённой роли; 'all' — дефолт.
	 */
	private const ROLE_LOC_MESSAGES = [
		'all' => 'TASKS_REPLICATION_FILTER_ROLE_ALL',
		'originator' => 'TASKS_REPLICATION_FILTER_ROLE_ORIGINATOR',
		'responsible' => 'TASKS_REPLICATION_FILTER_ROLE_RESPONSIBLE',
		'accomplice' => 'TASKS_REPLICATION_FILTER_ROLE_ACCOMPLICE',
		'auditor' => 'TASKS_REPLICATION_FILTER_ROLE_AUDITOR',
	];

	private const ROLE_DEFAULT = 'all';

	/**
	 * Default preset of the section filter - the same shape the task list uses for its
	 * "In progress" preset ({@see \Bitrix\Tasks\Filter\Preset\InProgressPreset}).
	 */
	private const PRESET_ACTIVE = 'filter_tasks_replication_active';

	/**
	 * Activity value of the "Active recurrence" field. An empty value means every rule: the
	 * field is preselected by the default preset, not by its own default.
	 */
	private const ACTIVITY_ACTIVE = 'ACTIVE';
	private const ACTIVITY_PAUSED = 'PAUSED';

	private int $userId = 0;
	private ErrorCollection $errorCollection;
	private ?SectionSettingsService $sectionSettingsService;
	private ?bool $sectionEnabled;

	public function __construct(
		$component = null,
		?SectionSettingsService $sectionSettingsService = null,
		?bool $sectionEnabled = null,
	)
	{
		parent::__construct($component);

		$this->errorCollection = new ErrorCollection();
		$this->sectionSettingsService = $sectionSettingsService;
		$this->sectionEnabled = $sectionEnabled;
	}

	public function configureActions(): array
	{
		// Component action configuration runs before the tasks module bootstrap.
		return [
			'saveSettings' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
				],
			],
		];
	}

	public function getErrors(): array
	{
		return $this->errorCollection->toArray();
	}

	public function getErrorByCode($code): ?Error
	{
		return $this->errorCollection->getErrorByCode($code);
	}

	public function executeComponent(): void
	{
		try
		{
			$this->includeModules();
		}
		catch (SystemException)
		{
			return;
		}

		// Серверный гейт раздела по флагу раздела: при off раздел недоступен.
		if (!$this->isSectionEnabled())
		{
			return;
		}

		$this->userId = (int)CurrentUser::get()->getId();

		$this->setTitle();

		$this->arResult['gridId'] = self::GRID_ID;
		$this->arResult['filterId'] = self::GRID_ID;
		$this->arResult['userId'] = $this->userId;
		$this->arResult['canCreate'] = TemplateAccessController::can(
			$this->userId,
			ActionDictionary::ACTION_TEMPLATE_CREATE,
		);
		// штатная форма добавления шаблона (AC-S-033) — раздел нового флоу не вводит
		$this->arResult['templateAddUrl'] = (new TemplatePathMaker(0, PathMaker::EDIT_ACTION, $this->userId))
			->makeEntityPath();
		$this->arResult['tzOffset'] = (int)\CTimeZone::GetOffset($this->userId ?: null, true);
		$this->arResult['period'] = $this->getSavedPeriod();
		$this->arResult['scheduleEnabled'] = $this->isScheduleEnabled();
		$this->arResult['roleId'] = $this->getSavedRole();
		$this->arResult['roleName'] = Loc::getMessage(self::ROLE_LOC_MESSAGES[$this->arResult['roleId']]);
		$this->arResult['sectionSettings'] = $this->getSectionSettingsDto($this->userId);
		$this->arResult['FILTER'] = $this->getFilter();

		$this->includeComponentTemplate();
	}

	public function saveSettingsAction(array $settings): ?array
	{
		try
		{
			$this->includeModules();
		}
		catch (SystemException)
		{
			$this->errorCollection->setError(
				new Error(
					'Failed to initialize section settings.',
					SectionSettingsService::ERROR_SAVE_FAILED,
				),
			);

			return null;
		}

		if (!$this->isSectionEnabled())
		{
			$this->errorCollection->setError(
				new Error(
					'Regular tasks section is disabled.',
					self::ERROR_FEATURE_DISABLED,
				),
			);

			return null;
		}

		$userId = (int)CurrentUser::get()->getId();
		$result = $this->getSectionSettingsService()->save($userId, $settings);
		if (!$result->isSuccess())
		{
			$this->errorCollection->add($result->getErrors());

			return null;
		}

		return $this->getSectionSettingsDto($userId);
	}

	/**
	 * @throws SystemException
	 */
	private function includeModules(): void
	{
		if (!Loader::includeModule('tasks'))
		{
			throw new SystemException('Cannot connect tasks module');
		}
	}

	private function setTitle(): void
	{
		if (($this->arParams['SET_TITLE'] ?? 'Y') === 'Y')
		{
			global $APPLICATION;
			$APPLICATION->SetTitle(Loc::getMessage('TASKS_REPLICATION_SECTION_TITLE'));
		}
	}

	private function getSectionSettingsDto(int $userId): array
	{
		$hasImportAccess = TaskAccessController::can(
			$userId,
			ActionDictionary::ACTION_TASK_IMPORT,
		);
		$hasExportAccess = TaskAccessController::can(
			$userId,
			ActionDictionary::ACTION_TASK_EXPORT,
		);

		return $this->getSectionSettingsService()->getDto(
			userId: $userId,
			hasImportAccess: $hasImportAccess,
			hasExportAccess: $hasExportAccess,
			importUrl: null,
		);
	}

	private function getSectionSettingsService(): SectionSettingsService
	{
		return $this->sectionSettingsService ??= new SectionSettingsService();
	}

	private function isSectionEnabled(): bool
	{
		return $this->sectionEnabled ?? Feature::isEnabled(RegularTasksSectionFeature::class);
	}

	/**
	 * Восстановленный масштаб графика (Неделя/Месяц/Квартал/Год): фронт сохраняет выбор
	 * в user options (BX.userOptions.save), раздел читает его при открытии — по образцу
	 * F_STATE у списка задач (CUserOptions). Невалидное значение → недельный масштаб.
	 */
	private function getSavedPeriod(): string
	{
		$saved = \CUserOptions::GetOption(
			'tasks.replication',
			'section',
			['period' => PeriodColumns::WEEK],
			$this->userId,
		);
		$period = is_array($saved) ? (string)($saved['period'] ?? '') : '';

		return PeriodColumns::isValidPeriod($period) ? $period : PeriodColumns::WEEK;
	}

	/**
	 * State of the "Schedule graph" switch for the initial control bar render.
	 *
	 * The personal state lives in the persisted grid view together with the visible columns.
	 * There is no separate section setting for it, which would create a second source of truth.
	 * The graph is off by default.
	 */
	private function isScheduleEnabled(): bool
	{
		return (new ReplicationGrid(new ReplicationGridSettings()))->isScheduleEnabled();
	}

	/**
	 * Восстановленный ролевой срез: фронт сохраняет выбор роли в user options
	 * (BX.userOptions.save), раздел читает его при открытии — тот же option-узел,
	 * что и у периода. Невалидное/пустое значение → «Все роли».
	 */
	private function getSavedRole(): string
	{
		$saved = \CUserOptions::GetOption(
			'tasks.replication',
			'section',
			['role' => self::ROLE_DEFAULT],
			$this->userId,
		);
		$role = is_array($saved) ? (string)($saved['role'] ?? '') : '';

		return isset(self::ROLE_LOC_MESSAGES[$role]) ? $role : self::ROLE_DEFAULT;
	}

	/**
	 * Standard section filter (AC-S-002/003): role, activity, and search.
	 * The frontend reads the field values and forwards them to grid, schedule, and agenda.
	 *
	 * An empty role means all roles. Activity has three states: all, active, and paused.
	 * "All" intentionally takes the empty value because main.ui.filter otherwise prepends
	 * "Not specified" (FieldAdapter::adapt), and an unset activity already means every rule.
	 *
	 * The section still opens on active rules only - that is the job of the default preset
	 * below, the same way the task list opens on its "In progress" preset. So a reset of the
	 * filter comes back to active rules, while the field itself stays unrestricted.
	 *
	 * @return array{FIELDS: array, PRESETS: array}
	 */
	private function getFilter(): array
	{
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/helper/filter.php');

		return [
			'FIELDS' => [
				[
					'id' => 'ROLE',
					'name' => Loc::getMessage('TASKS_REPLICATION_FILTER_ROLE'),
					'type' => 'list',
					'items' => [
						'originator' => Loc::getMessage('TASKS_REPLICATION_FILTER_ROLE_ORIGINATOR'),
						'responsible' => Loc::getMessage('TASKS_REPLICATION_FILTER_ROLE_RESPONSIBLE'),
						'accomplice' => Loc::getMessage('TASKS_REPLICATION_FILTER_ROLE_ACCOMPLICE'),
						'auditor' => Loc::getMessage('TASKS_REPLICATION_FILTER_ROLE_AUDITOR'),
					],
					'default' => true,
				],
				[
					'id' => 'ACTIVE',
					'name' => Loc::getMessage('TASKS_REPLICATION_FILTER_ACTIVE'),
					'type' => 'list',
					'items' => [
						'' => Loc::getMessage('TASKS_REPLICATION_FILTER_ACTIVE_ALL'),
						self::ACTIVITY_ACTIVE => Loc::getMessage('TASKS_REPLICATION_FILTER_ACTIVE_ONLY'),
						self::ACTIVITY_PAUSED => Loc::getMessage('TASKS_REPLICATION_FILTER_ACTIVE_PAUSED'),
					],
					'default' => true,
				],
				[
					'id' => 'PRIORITY',
					'name' => Loc::getMessage('TASKS_PRIORITY'),
					'type' => 'list',
					'items' => [
						1 => Loc::getMessage('TASKS_PRIORITY_1'),
						2 => Loc::getMessage('TASKS_PRIORITY_2'),
					],
				],
				$this->getUserFilterField(
					id: 'RESPONSIBLE_ID',
					name: (string)Loc::getMessage('TASKS_HELPER_FLT_ASSIGNEE_ID'),
					context: 'TASKS_FILTER_RESPONSIBLE_ID',
				),
				$this->getUserFilterField(
					id: 'CREATED_BY',
					name: (string)Loc::getMessage('TASKS_HELPER_FLT_CREATED_BY'),
					context: 'TASKS_FILTER_CREATED_BY',
				),
				[
					'id' => 'GROUP_ID',
					'name' => Loc::getMessage('TASKS_HELPER_FLT_GROUP'),
					'type' => 'entity_selector',
					'params' => [
						'multiple' => 'Y',
						'dialogOptions' => [
							'context' => 'TASKS_FILTER_GROUP_ID',
							'entities' => [
								[
									'id' => 'project',
									'options' => [
										'dynamicLoad' => true,
										'dynamicSearch' => true,
									],
								],
							],
						],
					],
				],
				[
					'id' => 'REPLICATE_TYPE',
					'name' => Loc::getMessage('TASKS_FILTER_REPLICATE_TYPE'),
					'type' => 'list',
					'items' => [
						'' => '',
						RegularityType::Schedule->value => Loc::getMessage('TASKS_FILTER_REPLICATE_TYPE_SCHEDULE'),
						RegularityType::Deadline->value => Loc::getMessage('TASKS_FILTER_REPLICATE_TYPE_DEADLINE'),
						RegularityType::AfterCompletion->value => Loc::getMessage('TASKS_FILTER_REPLICATE_TYPE_AFTER_COMPLETION'),
					],
					'default' => true,
				],
				[
					'id' => 'ID',
					'name' => Loc::getMessage('TASKS_FILTER_ID'),
					'type' => 'number',
				],
				$this->getUserFilterField(
					id: 'AUDITOR',
					name: (string)Loc::getMessage('TASKS_HELPER_FLT_AUDITOR'),
					context: 'TASKS_FILTER_AUDITOR',
				),
				$this->getUserFilterField(
					id: 'ACCOMPLICE',
					name: (string)Loc::getMessage('TASKS_HELPER_FLT_ACCOMPLICES'),
					context: 'TASKS_FILTER_ACCOMPLICE',
				),
			],
			'PRESETS' => [
				self::PRESET_ACTIVE => [
					'name' => Loc::getMessage('TASKS_REPLICATION_FILTER_PRESET_ACTIVE'),
					'default' => true,
					'fields' => [
						'ACTIVE' => self::ACTIVITY_ACTIVE,
					],
					'sort' => 1,
				],
			],
		];
	}

	private function getUserFilterField(
		string $id,
		string $name,
		string $context,
	): array
	{
		return [
			'id' => $id,
			'name' => $name,
			'type' => 'entity_selector',
			'params' => [
				'multiple' => 'Y',
				'dialogOptions' => [
					'context' => $context,
					'entities' => [
						[
							'id' => 'user',
							'options' => [
								'inviteEmployeeLink' => false,
							],
						],
					],
				],
			],
		];
	}
}
