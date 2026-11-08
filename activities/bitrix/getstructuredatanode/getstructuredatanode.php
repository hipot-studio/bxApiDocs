<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\HumanResources\Integration\Bizproc\StructureDataCollector;
use Bitrix\HumanResources\Integration\Bizproc\StructureDataReader;
use Bitrix\HumanResources\Integration\Bizproc\StructureDataSet;
use Bitrix\HumanResources\Integration\Bizproc\StructureMemberRow;
use Bitrix\HumanResources\Integration\Bizproc\StructureMemberScope;
use Bitrix\HumanResources\Integration\Bizproc\StructureNodeBinding;
use Bitrix\HumanResources\Integration\Bizproc\StructureNodeRow;
use Bitrix\HumanResources\Integration\Bizproc\StructureNodeValue;
use Bitrix\HumanResources\Integration\Bizproc\StructureSelection;
use Bitrix\HumanResources\Integration\Bizproc\StructureSelectionMode;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

/**
 * Elements of the org structure, the people holding roles in them and the link between the two.
 *
 * Business emptiness is an answer and not a failure: a source naming nothing, a structure with no element
 * to give and a set every filter emptied all close the node, publish what was gathered and write the code
 * of the reason to the journal. `Succeeded` says whether any element came back at all, which is what a
 * loop downstream asks. Only a broken read cancels the node.
 *
 * @property-read mixed $SourceNode
 * @property-read mixed $SelectionMode
 * @property-read mixed $NodeScope
 * @property-read mixed $MemberScope
 * @property-read mixed $MemberRoles
 * @property-read bool $IncludeChildMembers
 * @property-read bool $ExcludeHeads
 * @property-read bool $ExcludeInactive
 * @property-read bool $DeduplicateUsers
 */
class CBPGetStructureDataNode extends BaseActivity implements IBPConfigurableActivity
{
	private const SOURCE_NODE = 'SourceNode';
	private const SELECTION_MODE = 'SelectionMode';
	private const NODE_SCOPE = 'NodeScope';
	private const MEMBER_SCOPE = 'MemberScope';
	private const MEMBER_ROLES = 'MemberRoles';
	private const INCLUDE_CHILD_MEMBERS = 'IncludeChildMembers';
	private const EXCLUDE_HEADS = 'ExcludeHeads';
	private const EXCLUDE_INACTIVE = 'ExcludeInactive';
	private const DEDUPLICATE_USERS = 'DeduplicateUsers';

	private const EMPTY_JSON = '[]';

	/**
	 * Values of the node scope setting. They stay the vocabulary of the node rather than that of the org
	 * structure: saved templates hold them, and `NodeTypes` publishes them back.
	 */
	private const SCOPE_DEPARTMENT = 'department';
	private const SCOPE_TEAM = 'team';
	private const SCOPE_ANY = 'any';

	/**
	 * The settings a new node starts with, written out instead of read off the enums of humanresources:
	 * the engine builds every activity of a template before anything checks the modules, so the
	 * constructor must run on a portal where humanresources cannot be loaded at all. A test keeps these
	 * two in step with {@see StructureSelectionMode::Element} and {@see StructureMemberScope::Heads}.
	 */
	private const DEFAULT_SELECTION_MODE = 'element';
	private const DEFAULT_MEMBER_SCOPE = 'heads';

	protected static $requiredModules = ['humanresources'];

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = array_merge(
			[
				'Title' => '',
				self::SOURCE_NODE => null,
				self::SELECTION_MODE => self::DEFAULT_SELECTION_MODE,
				self::NODE_SCOPE => self::SCOPE_DEPARTMENT,
				self::MEMBER_SCOPE => self::DEFAULT_MEMBER_SCOPE,
				self::MEMBER_ROLES => [],
				self::INCLUDE_CHILD_MEMBERS => 'N',
				self::EXCLUDE_HEADS => 'N',
				self::EXCLUDE_INACTIVE => 'Y',
				self::DEDUPLICATE_USERS => 'Y',
			],
			self::emptyResult(),
		);

		$this->setPropertiesTypes(array_merge(
			[
				self::SOURCE_NODE => ['Type' => FieldType::ENTITYSELECTOR, 'Multiple' => true],
				self::MEMBER_ROLES => ['Type' => FieldType::SELECT, 'Multiple' => true],
				self::INCLUDE_CHILD_MEMBERS => ['Type' => FieldType::BOOL],
				self::EXCLUDE_HEADS => ['Type' => FieldType::BOOL],
				self::EXCLUDE_INACTIVE => ['Type' => FieldType::BOOL],
				self::DEDUPLICATE_USERS => ['Type' => FieldType::BOOL],
			],
			self::returnPropertyTypes(),
		));
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	protected static function createReader(): StructureDataReader
	{
		return new StructureDataReader();
	}

	protected function createCollector(StructureDataReader $reader): StructureDataCollector
	{
		return new StructureDataCollector($reader);
	}

	/** @return list<string> the results the node publishes, in the order the description declares them */
	public static function returnPropertyNames(): array
	{
		return array_keys(self::returnPropertyTypes());
	}

	/**
	 * @param array $context the saved activity, as {@see \CBPWorkflowTemplateLoader::findActivityByName()}
	 *   answers with: the selector of the source is built over the elements already picked
	 */
	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		if (!static::checkModules())
		{
			return [];
		}

		return [
			self::SOURCE_NODE => [
				'Name' => Loc::getMessage('BPGSDN_FIELD_SOURCE') ?? '',
				'FieldName' => 'source_node',
				'Type' => FieldType::ENTITYSELECTOR,
				'Multiple' => true,
				'Required' => false,
				'AllowSelection' => true,
				'Settings' => self::sourceNodeSettings($context),
				'Getter' => static fn(
					PropertiesDialog $dialog,
					array $property,
					array $activity,
					bool $compatible,
				) => self::sourceNodeDialogValue($activity['Properties'][self::SOURCE_NODE] ?? null),
			],
			self::SELECTION_MODE => self::selectField(
				'selection_mode',
				'BPGSDN_FIELD_MODE',
				self::modeOptions(),
				self::DEFAULT_SELECTION_MODE,
			),
			self::NODE_SCOPE => self::selectField(
				'node_scope',
				'BPGSDN_FIELD_SCOPE',
				self::scopeOptions(),
				self::SCOPE_DEPARTMENT,
			),
			self::MEMBER_SCOPE => self::selectField(
				'member_scope',
				'BPGSDN_FIELD_MEMBERS',
				self::memberScopeOptions(),
				self::DEFAULT_MEMBER_SCOPE,
			),
			// Drawn whatever the member scope is: the panel builds its controls once, when it opens, and a
			// field hidden here would stay folded after the scope next to it was switched to the roles -
			// leaving the save to ask for roles the author has nowhere to fill in. The other scopes carry
			// their own roles and never read this setting.
			self::MEMBER_ROLES => [
				'Name' => Loc::getMessage('BPGSDN_FIELD_ROLES') ?? '',
				'FieldName' => 'member_roles',
				'Type' => FieldType::SELECT,
				'Multiple' => true,
				'Required' => false,
				'Options' => self::roleOptions(),
				'AllowSelection' => false,
			],
			self::INCLUDE_CHILD_MEMBERS => self::boolField(
				'include_child_members',
				'BPGSDN_FIELD_INCLUDE_CHILD_MEMBERS',
				'N',
			),
			self::EXCLUDE_HEADS => self::boolField('exclude_heads', 'BPGSDN_FIELD_EXCLUDE_HEADS', 'N'),
			self::EXCLUDE_INACTIVE => self::boolField('exclude_inactive', 'BPGSDN_FIELD_EXCLUDE_INACTIVE', 'Y'),
			self::DEDUPLICATE_USERS => self::boolField('deduplicate_users', 'BPGSDN_FIELD_DEDUPLICATE', 'Y'),
		];
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		$savedActivity = $dialog === null
			? null
			: \CBPWorkflowTemplateLoader::findActivityByName($dialog->getWorkflowTemplate(), $dialog->getActivityName())
		;

		return static::getPropertiesMap(
			$dialog?->getDocumentType() ?? [],
			is_array($savedActivity) ? $savedActivity : [],
		);
	}

	public static function validateProperties($testProperties = [], ?CBPWorkflowTemplateUser $user = null)
	{
		$errors = parent::validateProperties($testProperties, $user);
		if (!static::checkModules())
		{
			return $errors;
		}

		$nodeTypes = self::nodeTypesOfProperty($testProperties[self::NODE_SCOPE] ?? null);
		if ($nodeTypes === null || !static::createReader()->isAvailable($nodeTypes))
		{
			$errors[] = self::validationError(
				'NotAvailable',
				self::NODE_SCOPE,
				'BPGSDN_ERROR_SCOPE_UNAVAILABLE',
			);

			return $errors;
		}

		if (self::selectionModeOfProperty($testProperties[self::SELECTION_MODE] ?? null) === null)
		{
			$errors[] = self::validationError('NotAvailable', self::SELECTION_MODE, 'BPGSDN_ERROR_UNKNOWN_MODE');

			return $errors;
		}

		$memberScope = self::memberScopeOfProperty($testProperties[self::MEMBER_SCOPE] ?? null);
		if ($memberScope === null)
		{
			$errors[] = self::validationError('NotAvailable', self::MEMBER_SCOPE, 'BPGSDN_ERROR_UNKNOWN_MEMBER_SCOPE');

			return $errors;
		}

		$roles = self::rolesOfProperty($testProperties[self::MEMBER_ROLES] ?? null, $nodeTypes);
		if ($memberScope === StructureMemberScope::ByRole && $roles === [])
		{
			$errors[] = self::validationError('NotExist', self::MEMBER_ROLES, 'BPGSDN_ERROR_ROLES_REQUIRED');
		}

		return $errors;
	}

	protected function reInitialize(): void
	{
		$this->setResults(self::emptyResult());

		parent::reInitialize();
	}

	protected function internalExecute(): ErrorCollection
	{
		$errors = new ErrorCollection();
		$this->setResults(self::emptyResult());

		try
		{
			$this->publish($this->readDataSet());
		}
		catch (\Throwable $exception)
		{
			// The message of the read may carry the query that broke; the journal of the run is read by
			// whoever opens the process, so it gets the phrase and the log of the portal gets the exception.
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
			$this->setResults(self::emptyResult());
			$errors->setError(new Error(Loc::getMessage('BPGSDN_ERROR_STRUCTURE_FAILURE') ?? ''));
		}

		return $errors;
	}

	private function readDataSet(): StructureDataSet
	{
		$nodeTypes = self::nodeTypesOfProperty($this->{self::NODE_SCOPE});
		$mode = self::selectionModeOfProperty($this->{self::SELECTION_MODE});
		$memberScope = self::memberScopeOfProperty($this->{self::MEMBER_SCOPE});
		if ($nodeTypes === null || $mode === null || $memberScope === null)
		{
			// A setting no vocabulary knows is a node that cannot be run at all, which is what an
			// unavailable structure means to the flow as well: an answer, not a broken read.
			return new StructureDataSet(reason: StructureDataCollector::REASON_UNAVAILABLE);
		}

		$reader = static::createReader();
		$selection = new StructureSelection(
			// The kind of element bounds what the walk publishes, not where it starts from: a department is
			// a lawful source of its child teams, and a team of its parent department.
			$reader->resolveNodeIds(
				self::sourceTokens($this->{self::SOURCE_NODE}),
				NodeEntityType::cases(),
				StructureDataCollector::NODE_LIMIT,
			),
			$mode,
			$nodeTypes,
			StructureDataCollector::NODE_LIMIT,
		);

		return $this->createCollector($reader)->collect(
			$selection,
			$memberScope,
			self::rolesOfProperty($this->{self::MEMBER_ROLES}, $nodeTypes),
			(bool)$this->{self::INCLUDE_CHILD_MEMBERS},
			(bool)$this->{self::EXCLUDE_HEADS},
			(bool)$this->{self::EXCLUDE_INACTIVE},
			(bool)$this->{self::DEDUPLICATE_USERS},
		);
	}

	private function publish(StructureDataSet $set): void
	{
		$this->setResults([
			'NodeIds' => array_map(static fn(StructureNodeRow $row): int => $row->id, $set->nodes),
			'NodeNames' => array_map(static fn(StructureNodeRow $row): string => $row->name, $set->nodes),
			'NodeTypes' => array_map(self::scopeOfNodeType(...), $set->nodes),
			'NodeActiveFlags' => array_map(static fn(StructureNodeRow $row): bool => $row->active, $set->nodes),
			'NodeParentIds' => array_map(static fn(StructureNodeRow $row): int => $row->parentId ?? 0, $set->nodes),
			'NodeLevels' => array_map(static fn(StructureNodeRow $row): int => $row->level, $set->nodes),
			'NodePaths' => array_map(static fn(StructureNodeRow $row): string => $row->path, $set->nodes),
			'NodesJson' => self::encode(array_map(
				static fn(StructureNodeRow $row): array => [
					'id' => $row->id,
					'name' => $row->name,
					'type' => self::scopeOfNodeType($row),
					'active' => $row->active,
					'parentId' => $row->parentId ?? 0,
					'level' => $row->level,
					'path' => $row->path,
				],
				$set->nodes,
			)),
			'NodesCount' => count($set->nodes),
			'MemberUsers' => array_map(
				static fn(StructureMemberRow $row): string => 'user_' . $row->userId,
				$set->members,
			),
			'MemberNodeIds' => array_map(static fn(StructureMemberRow $row): int => $row->nodeId, $set->members),
			'MemberRoleCodes' => array_map(
				static fn(StructureMemberRow $row): string => $row->role->value,
				$set->members,
			),
			'MembersJson' => self::encode(array_map(
				static fn(StructureMemberRow $row): array => [
					'userId' => $row->userId,
					'nodeId' => $row->nodeId,
					'nodeName' => $row->nodeName,
					'role' => $row->role->value,
				],
				$set->members,
			)),
			'MembersCount' => count($set->members),
			'HeadUsers' => self::usersOfRoles($set->members, [NodeMemberRole::Head, NodeMemberRole::TeamHead]),
			'DeputyUsers' => self::usersOfRoles(
				$set->members,
				[NodeMemberRole::DeputyHead, NodeMemberRole::TeamDeputyHead],
			),
			'EmployeeUsers' => self::usersOfRoles(
				$set->members,
				[NodeMemberRole::Employee, NodeMemberRole::TeamEmployee],
			),
			'Users' => array_map(static fn(int $userId): string => 'user_' . $userId, $set->userIds),
			'Truncated' => $set->truncated,
			'Succeeded' => $set->nodes !== [],
		]);

		$this->trackOutcome($set);
	}

	/**
	 * The results go through {@see BaseActivity::setProperty()} one by one and not through
	 * {@see \CBPActivity::setProperties()}: only the former also refreshes the prepared value a reference
	 * of another node is answered with, and a raw write would hand that reference the empty result instead.
	 *
	 * @param array<string, mixed> $results
	 */
	private function setResults(array $results): void
	{
		foreach ($results as $property => $value)
		{
			$this->setProperty($property, $value);
		}
	}

	private function trackOutcome(StructureDataSet $set): void
	{
		if ($set->reason === null)
		{
			$this->track('BPGSDN_TRACK_RESOLVED', [
				'#NODES#' => (string)count($set->nodes),
				'#MEMBERS#' => (string)count($set->members),
				'#USERS#' => (string)count($set->userIds),
			]);

			return;
		}

		$this->track(
			match ($set->reason)
			{
				StructureDataCollector::REASON_NO_SOURCE => 'BPGSDN_TRACK_NO_SOURCE',
				StructureDataCollector::REASON_NO_NODES => 'BPGSDN_TRACK_NO_NODES',
				StructureDataCollector::REASON_NO_MEMBERS => 'BPGSDN_TRACK_NO_MEMBERS',
				StructureDataCollector::REASON_ALL_FILTERED => 'BPGSDN_TRACK_ALL_FILTERED',
				StructureDataCollector::REASON_UNAVAILABLE => 'BPGSDN_TRACK_UNAVAILABLE',
				default => 'BPGSDN_TRACK_TRUNCATED',
			},
			['#REASON#' => $set->reason, '#NODES#' => (string)count($set->nodes)],
		);
	}

	protected function track(string $messageId, array $replacements = []): void
	{
		$this->log((string)Loc::getMessage($messageId, $replacements), 0, CBPTrackingType::Custom);
	}

	/** @param list<array<string, mixed>> $rows */
	private static function encode(array $rows): string
	{
		return $rows === []
			? self::EMPTY_JSON
			: Json::encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
		;
	}

	/**
	 * The people of one group of roles, always without repeats: the rows of the members block are what
	 * keeps the link person to element to role.
	 *
	 * @param list<StructureMemberRow> $members
	 * @param list<NodeMemberRole> $roles
	 * @return list<string>
	 */
	private static function usersOfRoles(array $members, array $roles): array
	{
		$userIds = [];
		foreach ($members as $row)
		{
			if (in_array($row->role, $roles, true))
			{
				$userIds[$row->userId] = $row->userId;
			}
		}

		return array_map(static fn(int $userId): string => 'user_' . $userId, array_values($userIds));
	}

	/** @return array<string, mixed> the results as a run that gathered nothing leaves them */
	private static function emptyResult(): array
	{
		$empty = [];
		foreach (self::returnPropertyTypes() as $property => $type)
		{
			$empty[$property] = match (true)
			{
				($type['Multiple'] ?? false) === true => [],
				$type['Type'] === FieldType::INT => 0,
				$type['Type'] === FieldType::BOOL => false,
				default => self::EMPTY_JSON,
			};
		}

		return $empty;
	}

	/** @return array<string, array{Type: string, Multiple?: bool}> */
	private static function returnPropertyTypes(): array
	{
		return [
			'NodeIds' => ['Type' => FieldType::INT, 'Multiple' => true],
			'NodeNames' => ['Type' => FieldType::STRING, 'Multiple' => true],
			'NodeTypes' => ['Type' => FieldType::STRING, 'Multiple' => true],
			'NodeActiveFlags' => ['Type' => FieldType::BOOL, 'Multiple' => true],
			'NodeParentIds' => ['Type' => FieldType::INT, 'Multiple' => true],
			'NodeLevels' => ['Type' => FieldType::INT, 'Multiple' => true],
			'NodePaths' => ['Type' => FieldType::STRING, 'Multiple' => true],
			'NodesJson' => ['Type' => FieldType::TEXT],
			'NodesCount' => ['Type' => FieldType::INT],
			'MemberUsers' => ['Type' => FieldType::USER, 'Multiple' => true],
			'MemberNodeIds' => ['Type' => FieldType::INT, 'Multiple' => true],
			'MemberRoleCodes' => ['Type' => FieldType::STRING, 'Multiple' => true],
			'MembersJson' => ['Type' => FieldType::TEXT],
			'MembersCount' => ['Type' => FieldType::INT],
			'HeadUsers' => ['Type' => FieldType::USER, 'Multiple' => true],
			'DeputyUsers' => ['Type' => FieldType::USER, 'Multiple' => true],
			'EmployeeUsers' => ['Type' => FieldType::USER, 'Multiple' => true],
			'Users' => ['Type' => FieldType::USER, 'Multiple' => true],
			'Truncated' => ['Type' => FieldType::BOOL],
			'Succeeded' => ['Type' => FieldType::BOOL],
		];
	}

	/**
	 * The selector of the source is the very one the employee triggers bind their nodes with, so the
	 * dialog of this node shows one and the same tree. Without humanresources there is nothing to pick
	 * from, and the field stays a plain one taking an expression.
	 */
	private static function sourceNodeSettings(array $context): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		return [
			'entity' => StructureNodeBinding::selectorEntity(),
			'items' => StructureNodeBinding::selectorItems(
				self::sourceNodeBinding($context['Properties'][self::SOURCE_NODE] ?? null),
			),
		];
	}

	/**
	 * The source as the binding of the selector reads it. A bare node id is a form the field takes by hand
	 * and the execution resolves, but no access code the binding knows: left as it is, the selector opens
	 * empty on it and the next save drops the source. It names the node together with its subordinates.
	 *
	 * @return list<string>
	 */
	private static function sourceNodeBinding(mixed $storedValue): array
	{
		return array_values(array_unique(StructureNodeValue::toAccessCodes(\CBPHelper::flatten($storedValue))));
	}

	private static function sourceNodeDialogValue(mixed $storedValue): array
	{
		return Loader::includeModule('humanresources')
			? StructureNodeBinding::toDialogValue(self::sourceNodeBinding($storedValue))
			: \CBPHelper::flatten($storedValue)
		;
	}

	private static function selectField(
		string $fieldName,
		string $messageId,
		array $options,
		string $default,
	): array
	{
		return [
			'Name' => Loc::getMessage($messageId) ?? '',
			'FieldName' => $fieldName,
			'Type' => FieldType::SELECT,
			'Required' => false,
			'Options' => $options,
			'Default' => $default,
			'AllowSelection' => false,
		];
	}

	private static function boolField(string $fieldName, string $messageId, string $default): array
	{
		return [
			'Name' => Loc::getMessage($messageId) ?? '',
			'FieldName' => $fieldName,
			'Type' => FieldType::BOOL,
			'Required' => false,
			'Default' => $default,
			'AllowSelection' => false,
		];
	}

	/** @return array<string, string> */
	private static function modeOptions(): array
	{
		return [
			StructureSelectionMode::Element->value => Loc::getMessage('BPGSDN_MODE_ELEMENT') ?? '',
			StructureSelectionMode::All->value => Loc::getMessage('BPGSDN_MODE_ALL') ?? '',
			StructureSelectionMode::Parent->value => Loc::getMessage('BPGSDN_MODE_PARENT') ?? '',
			StructureSelectionMode::Children->value => Loc::getMessage('BPGSDN_MODE_CHILDREN') ?? '',
			StructureSelectionMode::Branch->value => Loc::getMessage('BPGSDN_MODE_BRANCH') ?? '',
		];
	}

	/** @return array<string, string> */
	private static function scopeOptions(): array
	{
		return [
			self::SCOPE_DEPARTMENT => Loc::getMessage('BPGSDN_SCOPE_DEPARTMENT') ?? '',
			self::SCOPE_TEAM => Loc::getMessage('BPGSDN_SCOPE_TEAM') ?? '',
			self::SCOPE_ANY => Loc::getMessage('BPGSDN_SCOPE_ANY') ?? '',
		];
	}

	/** @return array<string, string> */
	private static function memberScopeOptions(): array
	{
		return [
			StructureMemberScope::None->value => Loc::getMessage('BPGSDN_MEMBERS_NONE') ?? '',
			StructureMemberScope::Heads->value => Loc::getMessage('BPGSDN_MEMBERS_HEADS') ?? '',
			StructureMemberScope::HeadsAndDeputies->value => Loc::getMessage('BPGSDN_MEMBERS_HEADS_DEPUTIES') ?? '',
			StructureMemberScope::Members->value => Loc::getMessage('BPGSDN_MEMBERS_MEMBERS') ?? '',
			StructureMemberScope::ByRole->value => Loc::getMessage('BPGSDN_MEMBERS_BY_ROLE') ?? '',
		];
	}

	/** @return array<string, string> */
	private static function roleOptions(): array
	{
		return [
			NodeMemberRole::Head->value => Loc::getMessage('BPGSDN_ROLE_HEAD') ?? '',
			NodeMemberRole::DeputyHead->value => Loc::getMessage('BPGSDN_ROLE_DEPUTY_HEAD') ?? '',
			NodeMemberRole::Employee->value => Loc::getMessage('BPGSDN_ROLE_EMPLOYEE') ?? '',
			NodeMemberRole::TeamHead->value => Loc::getMessage('BPGSDN_ROLE_TEAM_HEAD') ?? '',
			NodeMemberRole::TeamDeputyHead->value => Loc::getMessage('BPGSDN_ROLE_TEAM_DEPUTY_HEAD') ?? '',
			NodeMemberRole::TeamEmployee->value => Loc::getMessage('BPGSDN_ROLE_TEAM_EMPLOYEE') ?? '',
		];
	}

	/**
	 * A setting reaches a node as whatever the dialog, an expression or an older template put there, so
	 * every value of a vocabulary is read the same way: a string, a single-element list of one, any case
	 * and any surrounding spaces.
	 */
	private static function settingValue(mixed $value): ?string
	{
		if (is_array($value))
		{
			if (count($value) !== 1)
			{
				return null;
			}

			$value = reset($value);
		}

		return is_string($value) ? mb_strtolower(trim($value)) : null;
	}

	/** @return list<NodeEntityType>|null the kinds of node the scope names, null when it names none */
	private static function nodeTypesOfProperty(mixed $value): ?array
	{
		return match (self::settingValue($value))
		{
			self::SCOPE_DEPARTMENT => [NodeEntityType::DEPARTMENT],
			self::SCOPE_TEAM => [NodeEntityType::TEAM],
			self::SCOPE_ANY => [NodeEntityType::DEPARTMENT, NodeEntityType::TEAM],
			default => null,
		};
	}

	/** The scope one node of the answer belongs to, in the vocabulary of the setting. */
	private static function scopeOfNodeType(StructureNodeRow $row): string
	{
		return $row->type === NodeEntityType::TEAM ? self::SCOPE_TEAM : self::SCOPE_DEPARTMENT;
	}

	private static function selectionModeOfProperty(mixed $value): ?StructureSelectionMode
	{
		$setting = self::settingValue($value);

		return $setting === null ? null : StructureSelectionMode::tryFrom($setting);
	}

	private static function memberScopeOfProperty(mixed $value): ?StructureMemberScope
	{
		$setting = self::settingValue($value);

		return $setting === null ? null : StructureMemberScope::tryFrom($setting);
	}

	/**
	 * Roles the roles setting names, kept to the ones the kinds of node hold and ordered the way the
	 * dictionary orders them. A value naming nothing is dropped: the setting travels through the form of
	 * the dialog and may hold a role of the other kind after the kind was changed.
	 *
	 * @param list<NodeEntityType> $nodeTypes
	 * @return list<NodeMemberRole>
	 */
	private static function rolesOfProperty(mixed $value, array $nodeTypes): array
	{
		$named = [];
		foreach (\CBPHelper::flatten($value) as $item)
		{
			$role = is_string($item) ? NodeMemberRole::tryFrom(mb_strtoupper(trim($item))) : null;
			if ($role !== null)
			{
				$named[$role->value] = true;
			}
		}

		return array_values(array_filter(
			StructureMemberScope::rolesOfNodeTypes($nodeTypes),
			static fn(NodeMemberRole $role): bool => isset($named[$role->value]),
		));
	}

	/**
	 * The raw source setting as the tokens the org structure is asked to name nodes by: what the selector
	 * stored, with the `:F` mark of the members of the node itself dropped, and what an expression computed.
	 *
	 * @return list<int|string>
	 */
	private static function sourceTokens(mixed $value): array
	{
		$tokens = [];
		foreach (\CBPHelper::flatten($value) as $item)
		{
			if (is_int($item))
			{
				$tokens[] = $item;

				continue;
			}

			if (!is_string($item))
			{
				continue;
			}

			$item = trim($item);
			$tokens[] = str_ends_with(mb_strtoupper($item), ':F') ? substr($item, 0, -2) : $item;
		}

		return $tokens;
	}

	/** @return array{code: string, parameter: string, message: string} */
	private static function validationError(string $code, string $parameter, string $messageId): array
	{
		return [
			'code' => $code,
			'parameter' => $parameter,
			'message' => Loc::getMessage($messageId) ?? '',
		];
	}
}
