<?php

namespace Bitrix\Bizproc\Service;

use Bitrix\Bizproc\Integration\AiAssistant\ActivityAiPropertyConverter;
use Bitrix\Bizproc\Integration\AiAssistant\Interface\IBPActivityAiDescription;
use Bitrix\Bizproc\Internal\Entity\Activity\Result\ActivityAiDescriptionResult;
use Bitrix\Bizproc\Internal\Entity\Activity\Setting;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingOption;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingOptionCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingType;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class AiDescription extends \CBPRuntimeService
{
	/**
	 * @param string $code Activity code like delayactivity
	 * @param list<string> $documentType ['module', 'entityType', 'documentType']
	 *
	 * @return Result|ActivityAiDescriptionResult
	 */
	public function getActivityDescription(string $code, array $documentType): Result|ActivityAiDescriptionResult
	{
		$runtime = \CBPRuntime::getRuntime();
		if (!$runtime->includeActivityFile($code))
		{
			return (new Result())->addError(new Error('Activity class file not found'));
		}

		if (!$runtime->includeActivityAiDescriptionFile($code))
		{
			return (new Result())->addError(new Error('Ai description file not found'));
		}

		$classname = $this->getActivityDescriptionClassName($code);
		if (!class_exists($classname))
		{
			return (new Result())->addError(new Error('Ai description class not found'));
		}

		$object = new $classname();
		if (!$object instanceof IBPActivityAiDescription)
		{
			return (new Result())
				->addError(new Error('Ai description class not implements IBPActivityAiDescription interface'))
			;
		}

		return new ActivityAiDescriptionResult(
			code: $code,
			settings: $object->getAiDescribedSettings($documentType),
		);
	}

	public function getActivityDescriptionClassName(string $code): string
	{
		return "CBPAI{$code}";
	}

	public function getEditableDocumentFieldSettings(array $documentType): ?SettingCollection
	{
		$fields = \CBPRuntime::GetRuntime()
			->getDocumentService()
			->getDocumentFields($documentType)
		;

		if (!is_array($fields))
		{
			return null;
		}

		$settings = new SettingCollection();
		foreach ($fields as $id => $field)
		{
			if (empty($field['Editable']))
			{
				continue;
			}

			$settings->add(
				new Setting(
					name: $id,
					description: (string)($field['Name'] ?? ''),
					type: $this->getSettingType($field, $documentType),
					required: !empty($field['Required']),
					options: $this->getOptions($field),
				)
			);
		}

		return $settings;
	}

	protected function getOptions(array $field): ?SettingOptionCollection
	{
		if (empty($field['Options']) || ! is_array($field['Options']))
		{
			return null;
		}

		$options = new SettingOptionCollection();
		foreach ($field['Options'] as $key => $value)
		{
			$options->add(new SettingOption(id: (string)$key, name: (string)$value));
		}

		return $options;
	}

	private function getSettingType(array $field, array $documentType): SettingType|string
	{
		$converter = new ActivityAiPropertyConverter();
		$fieldType = $converter->getFieldType($field, $documentType);
		if (!$fieldType)
		{
			return (string)($field['BaseType'] ?? '');
		}

		return $converter->getSettingType($fieldType);
	}
}