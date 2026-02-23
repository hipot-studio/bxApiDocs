<?php

namespace Bitrix\Bizproc\Internal\Service\Activity;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Entity\Activity\Interface\FixedDocumentComplexActivity;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Activities;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use CBPRuntime;

use Bitrix\Main\Localization\Loc;

class ComplexActivityService
{
	private readonly Searcher $searcher;

	public function __construct(
		?Searcher $searcher = null,
	)
	{
		$this->searcher = $searcher ?? Container::instance()->getActivitySearcherService();
	}

	private const RULES_PARAM = 'Rules';

	public function getActivityDescriptionByCode(string $complexActivityCode): ?ActivityDescription
	{
		$description = $this->searcher->searchByCode($complexActivityCode);
		if (!$description)
		{
			return null;
		}

		if (!$description->getComplexActivitySettings())
		{
			return null;
		}

		if (!in_array(ActivityType::NODE->value, $description->getType(), true))
		{
			return null;
		}

		if ($description->getNodeType() !== ActivityNodeType::COMPLEX->value)
		{
			return null;
		}

		return $description;
	}

	public function getFixedDocumentTypeForNodeAction(string $activityType): ?array
	{
		CBPRuntime::getRuntime()->includeActivityFile($activityType);

		$className = 'CBP' . $activityType;
		if (
			!class_exists($className)
			|| !isset(class_implements($className, false)[FixedDocumentComplexActivity::class])
		)
		{
			return null;
		}

		/* @var FixedDocumentComplexActivity $className */
		return $className::getDocumentTypeForNodeAction();
	}

	public function getCorrespondingNodeActionActivityByName(string $complexActivityCode): Activities
	{
		$complexActivityDescription = $this->getActivityDescriptionByCode($complexActivityCode);
		if (!$complexActivityDescription)
		{
			return new Activities();
		}

		$settings = $complexActivityDescription->getComplexActivitySettings();
		if (!$settings || $settings->actionDictionary->isEmpty())
		{
			return new Activities();
		}

		$nodeActionDescriptionCollection = $this->searcher
			->searchByType(ActivityType::NODE_ACTION->value)
			->filter(
				fn(ActivityDescription $description)
					=> $settings->actionDictionary->get(
						$this->searcher->normalizeActivityCode($description->getClass())
					) !== null
			)
		;

		return (new Activities(
			$nodeActionDescriptionCollection->map(
				function (ActivityDescription $description) use ($settings): ActivityDescription
				{
					$normalizedActivityCode = $this->searcher->normalizeActivityCode($description->getClass());
					$action = $settings->actionDictionary->get($normalizedActivityCode);
					if ($action === null)
					{
						return $description;
					}

					$descriptionWithPreset = $action->presetId
						? $description->applyPresetById($action->presetId)
						: $description
					;

					$nodeActionPreset = $action->toPreset();
					if (empty($nodeActionPreset))
					{
						return $descriptionWithPreset;
					}

					return $descriptionWithPreset->applyPreset($nodeActionPreset);
				},
			),
		))->sort();
	}


	public function configureRuleProperty(): array
	{
		$defaultParamValue = [
			'i0' => [
				'portId' => 'i0',
				'ruleCards' => [],
			],
		];

		return [
			self::RULES_PARAM => [
				'Name' => Loc::getMessage('BIZPROC_BCA_RULES_PROPERTY_NAME'),
				'FieldName' => self::RULES_PARAM,
				'Type' => FieldType::RULES,
				'Required' => true,
				'Default' => $defaultParamValue,
			],
		];
	}
}
