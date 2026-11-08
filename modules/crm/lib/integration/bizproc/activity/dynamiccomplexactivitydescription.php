<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\Activity;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Dto\Complex;
use Bitrix\Bizproc\Activity\Dto\Complex\NodeAction;
use Bitrix\Bizproc\Activity\Dto\Complex\NodeActionDictionary;
use Bitrix\Bizproc\Activity\Enum\ActivityColorIndex;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Main\Config\Option;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

final class DynamicComplexActivityDescription
{
	private const CREATE_DYNAMIC_ACTIVITY_CODE = 'crmcreatedynamicactivity';
	private const RELATION_ACTIVITY_CODE = 'crmgetrelationdataactivity';

	public static function create(
		string $name,
		string $description,
		string $class,
		array $groups,
		array $actionNames,
		?string $createDynamicActivityPresetId = 'dynamic',
		bool $includeCreateRequisiteAction = false,
		bool $relationsEnabled = false,
		bool $deprecated = false,
	): array
	{
		// older bizproc versions have no $relationAction in Settings constructor
		$relationsDeclared = $relationsEnabled && property_exists(Complex\Settings::class, 'relationAction');

		$settingsArgs = [];
		if ($relationsDeclared)
		{
			$settingsArgs['relationAction'] = new NodeAction(activityCode: self::RELATION_ACTIVITY_CODE);
		}

		// older bizproc versions have no BlockAvailability::legacyDefault()
		if (method_exists(Complex\BlockAvailability::class, 'legacyDefault'))
		{
			$settingsArgs['availableBlocks'] = Complex\BlockAvailability::legacyDefault(
				filter: true,
				relations: $relationsDeclared,
			);
		}

		// The relation map is the result of the relation action, so it is published only along with it.
		$additionalResult = ['FilterReturnPropertiesMap'];
		if ($relationsDeclared)
		{
			$additionalResult[] = 'RelationReturnPropertiesMap';
		}

		$activityDescription = (new ActivityDescription(
			name: $name,
			description: $description,
			type: [ActivityType::NODE->value],
		))
			->setClass($class)
			->setNodeType(ActivityNodeType::COMPLEX->value)
			->setExcluded((bool)Option::get('bizproc', 'complex_activity_excluded', 1))
			->setIcon(Outline::SMART_PROCESS->name)
			->setColorIndex(ActivityColorIndex::BLUE->value)
			->setGroups($groups)
			->setComplexActivitySettings(
				new Complex\Settings(
					...$settingsArgs,
					actionDictionary: self::createActionDictionary(
						$actionNames,
						$createDynamicActivityPresetId,
						$includeCreateRequisiteAction,
					),
				),
			)
			->setAdditionalResult($additionalResult)
			->set('PROPERTIES', ['BaseSettingsAction' => 'setfieldactivity'])
		;

		if ($deprecated)
		{
			$activityDescription->setDeprecated(true);
		}

		return $activityDescription->toArray();
	}

	private static function createActionDictionary(
		array $actionNames,
		?string $createDynamicActivityPresetId,
		bool $includeCreateRequisiteAction,
	): NodeActionDictionary
	{
		$actions = [
			new NodeAction(
				activityCode: self::CREATE_DYNAMIC_ACTIVITY_CODE,
				sort: 100,
				presetId: $createDynamicActivityPresetId,
			),
			new NodeAction(
				activityCode: 'crmcreatetodoactivity',
				sort: 125,
			),
			new NodeAction(
				activityCode: 'setfieldactivity',
				customName: $actionNames['change'] ?? null,
				sort: 200,
			),
			new NodeAction(
				activityCode: 'crmchangerelationsactivity',
				sort: 300,
			),
			new NodeAction(
				activityCode: 'crmsetcompanyfield',
				customName: $actionNames['changeCompany'] ?? null,
				sort: 400,
			),
			new NodeAction(
				activityCode: 'crmsetcontactfield',
				customName: $actionNames['changeContact'] ?? null,
				sort: 500,
			),
			new NodeAction(activityCode: 'crmsetobserverfield', sort: 600),
			new NodeAction(activityCode: 'crmchangeresponsibleactivity', sort: 700),
			new NodeAction(activityCode: 'crmchangerequisiteactivity', sort: 800),
		];

		if ($includeCreateRequisiteAction)
		{
			$actions[] = new NodeAction(activityCode: 'crmcreaterequisiteactivity', sort: 850);
		}

		$actions = [
			...$actions,
			new NodeAction(activityCode: 'crmchangestatusactivity', sort: 900),
			new NodeAction(activityCode: 'crmcopydynamicactivity', sort: 1000),
			new NodeAction(activityCode: 'crmgenerateentitydocumentactivity', sort: 1050),
			new NodeAction(
				activityCode: 'crmtimelinecommentadd',
				customName: $actionNames['addComment'] ?? null,
				sort: 1051,
			),
			new NodeAction(activityCode: 'crmaddproductrow', sort: 1100),
			new NodeAction(activityCode: 'crmremoveproductrow', sort: 1200),
			new NodeAction(
				activityCode: 'deletedocumentactivity',
				customName: $actionNames['delete'] ?? null,
				sort: 1300,
			),
		];

		return new NodeActionDictionary(...$actions);
	}
}
