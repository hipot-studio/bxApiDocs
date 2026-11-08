<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Presentation;

use Bitrix\Crm\V2\Internal\Entity\Settings\ObjectValueInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

/** @internal */
final class SettingPresentationMapper
{
	public const ERROR_MAPPING_FAILED = 'SETTING_PRESENTATION_MAPPING_FAILED';
	public const ERROR_UNSUPPORTED_VALUE = 'UNSUPPORTED_SETTING_VALUE';

	public function __construct(private readonly SettingSchemaMapper $schemaMapper)
	{
	}

	public function map(SettingInterface $setting, bool $canEdit): Result
	{
		$result = new Result();

		try
		{
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
				'code' => $setting->getCode(),
				'value' => $value,
				'visible' => true,
				'canEdit' => $canEdit,
				'meta' => [
					'title' => $description->title,
					'hint' => $description->hint,
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
