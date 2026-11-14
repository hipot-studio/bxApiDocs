<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub;

use Bitrix\Crm\V2\Internal\Access\Settings\SettingPolicyEvaluator;
use Bitrix\Crm\V2\Internal\Entity\Settings\ObjectValueInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SearchTextBuilder;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

/** @internal */
final class SettingsHubMapper
{
	public const ERROR_MAPPING_FAILED = 'SETTING_PRESENTATION_MAPPING_FAILED';
	public const ERROR_UNSUPPORTED_VALUE = 'UNSUPPORTED_SETTING_VALUE';

	public function __construct(
		private readonly SettingsHubSchemaMapper $schemaMapper,
		private readonly SearchTextBuilder $searchTextBuilder,
	)
	{
	}

	public function map(SettingInterface $setting, Result $writeAccess): Result
	{
		$result = new Result();

		try
		{
			$disabledReason = null;
			if (!$writeAccess->isSuccess())
			{
				SettingPolicyEvaluator::assertDenial($writeAccess);
				$disabledReason = $writeAccess->getErrors()[0]->getMessage();
			}

			$description = $setting->getDescription();
			$schemaResult = $this->schemaMapper->map($description->schema);
			if (!$schemaResult->isSuccess())
			{
				return $result->addErrors($schemaResult->getErrors());
			}

			$value = $setting->getValue();
			if (is_object($value))
			{
				if (!$value instanceof ObjectValueInterface)
				{
					return $result->addError(
						new Error(
							'Setting value object cannot be serialized.',
							self::ERROR_UNSUPPORTED_VALUE,
						),
					);
				}

				$value = $value->toArray();
			}

			$result->setData([
				'id' => $setting->getId(),
				'value' => $value,
				'visible' => true,
				'editable' => $writeAccess->isSuccess(),
				'disabledReason' => $disabledReason,
				'meta' => [
					'title' => $description->title,
					'description' => $description->description,
					'note' => $description->note,
					'optionKey' => $description->optionKey,
					'searchText' => $this->searchTextBuilder->build($description),
					'schema' => $schemaResult->getData(),
				],
			]);
		}
		catch (Throwable)
		{
			return $result->addError(
				new Error(
					'Failed to map the setting presentation.',
					self::ERROR_MAPPING_FAILED,
				),
			);
		}

		return $result;
	}
}
