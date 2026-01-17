<?php

namespace Bitrix\Bizproc\Internal\Service\Activity;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Entity\Activity\Interface\FixedDocumentComplexActivity;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Activities;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\Main\Localization\Loc;
use CBPRuntime;

class ComplexActivityService
{
	private const RULES_PARAM = 'Rules';

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

	public function getCorrespondingNodeActionActivityByName(string $complexActivityName): Activities
	{
		/** @var Searcher $activitySearcher */
		$activitySearcher = \Bitrix\Main\DI\ServiceLocator::getInstance()->get('bizproc.runtime.activitysearcher.searcher');

		$filterLogic = function (ActivityDescription $description) use ($complexActivityName) {
			if (!in_array(ActivityType::NODE_ACTION->value, $description->getType(), true))
			{
				return false;
			}

			$nodeActionSettings = $description->getNodeActionSettings();
			if (empty($nodeActionSettings))
			{
				return true;
			}

			return in_array($complexActivityName, $nodeActionSettings['INCLUDE'] ?? [],true);
		};

		return $activitySearcher
			->searchByType(ActivityType::NODE_ACTION->value)
			->filter($filterLogic)
			->sort()
		;
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
