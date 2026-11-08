<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

final class FormDtoMapper extends Mapper
{
	/**
	 * @param WebForm[] $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(Form\FormDto::class);
		foreach ($items as $item)
		{
			if (!$item instanceof WebForm)
			{
				throw new ArgumentTypeException('items', WebForm::class);
			}

			$collection->add($this->mapForm($item, $fields));
		}

		return $collection;
	}

	private function mapForm(WebForm $form, array $fields): Form\FormDto
	{
		$dto = new Form\FormDto();
		$this->mapScalarFields(
			$dto,
			$form,
			$fields,
			[
				WebForm::FIELD_ID,
				WebForm::FIELD_CODE,
				WebForm::FIELD_NAME,
				WebForm::FIELD_ACTIVE,
				WebForm::FIELD_SYSTEM,
				WebForm::FIELD_LANGUAGE_ID,
				WebForm::FIELD_TEMPLATE_ID,
				WebForm::FIELD_CREATED_TIME,
				WebForm::FIELD_ACTIVE_CHANGED_BY_ID,
				WebForm::FIELD_ACTIVE_CHANGED_TIME,
			],
		);
		if ($this->isFieldSelected($fields, WebForm::FIELD_MODIFIED_TIME) && $form->modifiedTime !== null)
		{
			$dto->updatedTime = $form->modifiedTime;
		}

		if ($this->isFieldSelected($fields, WebForm::SECTION_APPEARANCE))
		{
			$dto->appearance = $form->appearance === null
				? null
				: $this->mapAppearance(
					$form->appearance,
					$this->getNestedFields($fields, WebForm::SECTION_APPEARANCE),
				)
			;
		}

		if ($this->isFieldSelected($fields, WebForm::SECTION_CRM))
		{
			$dto->crm = $form->crm === null
				? null
				: $this->mapCrmSettings(
					$form->crm,
					$this->getNestedFields($fields, WebForm::SECTION_CRM),
				)
			;
		}

		if ($this->isFieldSelected($fields, WebForm::SECTION_FIELDS))
		{
			$dto->{WebForm::SECTION_FIELDS} = $this->mapFields(
				$form->fields,
				$this->getNestedFields($fields, WebForm::SECTION_FIELDS),
			);
		}

		if ($this->isFieldSelected($fields, WebForm::SECTION_DEPENDENCIES))
		{
			$dto->dependencies = $this->mapDependencies(
				$form->dependencies,
				$this->getNestedFields($fields, WebForm::SECTION_DEPENDENCIES),
			);
		}

		if ($this->isFieldSelected($fields, WebForm::SECTION_PRESET_FIELDS))
		{
			$dto->presetFields = $this->mapPresetFields(
				$form->presetFields,
				$this->getNestedFields($fields, WebForm::SECTION_PRESET_FIELDS),
			);
		}

		if ($this->isFieldSelected($fields, WebForm::SECTION_RESPONSIBLE))
		{
			$dto->responsible = $form->responsible === null
				? null
				: $this->mapResponsible(
					$form->responsible,
					$this->getNestedFields($fields, WebForm::SECTION_RESPONSIBLE),
				)
			;
		}

		if ($this->isFieldSelected($fields, WebForm::SECTION_AGREEMENTS))
		{
			$dto->agreements = $form->agreements === null
				? null
				: $this->mapAgreements(
					$form->agreements,
					$this->getNestedFields($fields, WebForm::SECTION_AGREEMENTS),
				)
			;
		}

		if ($this->isFieldSelected($fields, WebForm::SECTION_SUBMISSION))
		{
			$dto->submission = $form->submission === null
				? null
				: $this->mapSubmission(
					$form->submission,
					$this->getNestedFields($fields, WebForm::SECTION_SUBMISSION),
				)
			;
		}

		if ($this->isFieldSelected($fields, WebForm::SECTION_PAYMENT))
		{
			$dto->payment = $form->payment === null
				? null
				: $this->mapPayment(
					$form->payment,
					$this->getNestedFields($fields, WebForm::SECTION_PAYMENT),
				)
			;
		}

		if ($this->isFieldSelected($fields, WebForm::SECTION_CALLBACK))
		{
			$dto->callback = $form->callback === null
				? null
				: $this->mapCallback(
					$form->callback,
					$this->getNestedFields($fields, WebForm::SECTION_CALLBACK),
				)
			;
		}

		if ($this->isFieldSelected($fields, WebForm::SECTION_BOOKING))
		{
			$dto->booking = $form->booking === null
				? null
				: $this->mapBooking(
					$form->booking,
					$this->getNestedFields($fields, WebForm::SECTION_BOOKING),
				)
			;
		}

		if ($this->isFieldSelected($fields, WebForm::SECTION_ANALYTICS))
		{
			$dto->analytics = $form->analytics === null
				? null
				: $this->mapAnalytics(
					$form->analytics,
					$this->getNestedFields($fields, WebForm::SECTION_ANALYTICS),
				)
			;
		}

		if ($this->isFieldSelected($fields, WebForm::SECTION_SPAM_PROTECTION))
		{
			$dto->spamProtection = $form->spamProtection === null
				? null
				: $this->mapSpamProtection(
					$form->spamProtection,
					$this->getNestedFields($fields, WebForm::SECTION_SPAM_PROTECTION),
				)
			;
		}

		if ($this->isFieldSelected($fields, WebForm::SECTION_INTEGRATIONS))
		{
			$dto->integrations = $form->integrations === null
				? null
				: $this->mapIntegrations(
					$form->integrations,
					$this->getNestedFields($fields, WebForm::SECTION_INTEGRATIONS),
				)
			;
		}

		return $dto;
	}

	private function mapAppearance(Section\Appearance $appearance, array $fields): Form\AppearanceDto
	{
		$dto = new Form\AppearanceDto();
		$this->mapScalarFields(
			$dto,
			$appearance,
			$fields,
			[
				Section\Appearance::FIELD_TITLE,
				Section\Appearance::FIELD_DESCRIPTION,
				Section\Appearance::FIELD_BUTTON_CAPTION,
				Section\Appearance::FIELD_BUTTON_TEXT_COLOR,
				Section\Appearance::FIELD_BUTTON_BACKGROUND_COLOR,
				Section\Appearance::FIELD_BACKGROUND_IMAGE_ID,
				Section\Appearance::FIELD_COPYRIGHT_REMOVED,
			],
		);

		return $dto;
	}

	private function mapCrmSettings(Section\CrmSettings $settings, array $fields): Form\CrmSettingsDto
	{
		$dto = new Form\CrmSettingsDto();
		$this->mapScalarFields(
			$dto,
			$settings,
			$fields,
			[
				Section\CrmSettings::FIELD_ENTITY_SCHEME,
				Section\CrmSettings::FIELD_DUPLICATE_MODE,
				Section\CrmSettings::FIELD_DEAL_CATEGORY_ID,
				Section\CrmSettings::FIELD_DEAL_DUPLICATE_CONTROL_ENABLED,
				Section\CrmSettings::FIELD_SMART_PROCESS_CATEGORY_ID,
				Section\CrmSettings::FIELD_SMART_PROCESS_DUPLICATE_CONTROL_ENABLED,
			],
		);

		return $dto;
	}

	/**
	 * @param null|array<Section\Field> $items
	 */
	private function mapFields(?array $items, array $fields): ?DtoCollection
	{
		if ($items === null)
		{
			return null;
		}

		$collection = new DtoCollection(Form\FieldDto::class);
		foreach ($items as $item)
		{
			if (!$item instanceof Section\Field)
			{
				throw new ArgumentTypeException('fields', Section\Field::class);
			}

			$collection->add($this->mapField($item, $fields));
		}

		return $collection;
	}

	private function mapField(Section\Field $field, array $fields): Form\FieldDto
	{
		$dto = new Form\FieldDto();
		$this->mapScalarFields(
			$dto,
			$field,
			$fields,
			[
				Section\Field::FIELD_NAME,
				Section\Field::FIELD_TYPE,
				Section\Field::FIELD_LABEL,
				Section\Field::FIELD_SORT,
				Section\Field::FIELD_REQUIRED,
				Section\Field::FIELD_MULTIPLE,
				Section\Field::FIELD_PLACEHOLDER,
				Section\Field::FIELD_VALUE_TYPE,
				Section\Field::FIELD_VALUE,
			],
		);

		return $dto;
	}

	/**
	 * @param null|array<Section\DependencyGroup> $items
	 */
	private function mapDependencies(?array $items, array $fields): ?DtoCollection
	{
		if ($items === null)
		{
			return null;
		}

		$collection = new DtoCollection(Form\DependencyGroupDto::class);
		foreach ($items as $item)
		{
			if (!$item instanceof Section\DependencyGroup)
			{
				throw new ArgumentTypeException('dependencies', Section\DependencyGroup::class);
			}

			$collection->add($this->mapDependencyGroup($item, $fields));
		}

		return $collection;
	}

	private function mapDependencyGroup(
		Section\DependencyGroup $group,
		array $fields,
	): Form\DependencyGroupDto
	{
		$dto = new Form\DependencyGroupDto();
		$this->mapScalarFields(
			$dto,
			$group,
			$fields,
			[Section\DependencyGroup::FIELD_LOGIC],
		);

		if ($this->isFieldSelected($fields, Section\DependencyGroup::FIELD_RULES))
		{
			$rules = $this->mapDependencyRules($group->rules);
			if ($rules !== null)
			{
				$dto->rules = $rules;
			}
		}

		return $dto;
	}

	/**
	 * @param null|array<Section\DependencyRule> $items
	 */
	private function mapDependencyRules(?array $items): ?DtoCollection
	{
		if ($items === null)
		{
			return null;
		}

		$collection = new DtoCollection(Form\DependencyRuleDto::class);
		foreach ($items as $item)
		{
			if (!$item instanceof Section\DependencyRule)
			{
				throw new ArgumentTypeException('rules', Section\DependencyRule::class);
			}

			$collection->add($this->mapDependencyRule($item));
		}

		return $collection;
	}

	private function mapDependencyRule(Section\DependencyRule $rule): Form\DependencyRuleDto
	{
		$dto = new Form\DependencyRuleDto();
		if ($rule->condition !== null)
		{
			$dto->condition = $this->mapDependencyCondition($rule->condition);
		}
		if ($rule->action !== null)
		{
			$dto->action = $this->mapDependencyAction($rule->action);
		}

		return $dto;
	}

	private function mapDependencyCondition(Section\DependencyCondition $condition): Form\DependencyConditionDto
	{
		$dto = new Form\DependencyConditionDto();
		$this->mapScalarFields(
			$dto,
			$condition,
			[],
			[
				Section\DependencyCondition::FIELD_FIELD_NAME,
				Section\DependencyCondition::FIELD_EVENT,
				Section\DependencyCondition::FIELD_VALUE,
				Section\DependencyCondition::FIELD_OPERATION,
			],
		);

		return $dto;
	}

	private function mapDependencyAction(Section\DependencyAction $action): Form\DependencyActionDto
	{
		$dto = new Form\DependencyActionDto();
		$this->mapScalarFields(
			$dto,
			$action,
			[],
			[
				Section\DependencyAction::FIELD_FIELD_NAME,
				Section\DependencyAction::FIELD_TYPE,
				Section\DependencyAction::FIELD_VALUE,
			],
		);

		return $dto;
	}

	/**
	 * @param null|array<Section\PresetField> $items
	 */
	private function mapPresetFields(?array $items, array $fields): ?DtoCollection
	{
		if ($items === null)
		{
			return null;
		}

		$collection = new DtoCollection(Form\PresetFieldDto::class);
		foreach ($items as $item)
		{
			if (!$item instanceof Section\PresetField)
			{
				throw new ArgumentTypeException('presetFields', Section\PresetField::class);
			}

			$collection->add($this->mapPresetField($item, $fields));
		}

		return $collection;
	}

	private function mapPresetField(Section\PresetField $field, array $fields): Form\PresetFieldDto
	{
		$dto = new Form\PresetFieldDto();
		$this->mapScalarFields(
			$dto,
			$field,
			$fields,
			[
				Section\PresetField::FIELD_ENTITY_NAME,
				Section\PresetField::FIELD_FIELD_NAME,
				Section\PresetField::FIELD_VALUE,
			],
		);

		return $dto;
	}

	private function mapResponsible(Section\Responsible $responsible, array $fields): Form\ResponsibleDto
	{
		$dto = new Form\ResponsibleDto();
		$this->mapScalarFields(
			$dto,
			$responsible,
			$fields,
			[
				Section\Responsible::FIELD_USER_IDS,
				Section\Responsible::FIELD_CHECK_WORK_TIME,
			],
		);

		return $dto;
	}

	private function mapAgreements(Section\Agreements $agreements, array $fields): Form\AgreementsDto
	{
		$dto = new Form\AgreementsDto();
		$this->mapScalarFields(
			$dto,
			$agreements,
			$fields,
			[Section\Agreements::FIELD_ENABLED],
		);

		if ($this->isFieldSelected($fields, Section\Agreements::FIELD_ITEMS))
		{
			$items = $this->mapAgreementItems($agreements->items);
			if ($items !== null)
			{
				$dto->items = $items;
			}
		}

		return $dto;
	}

	/**
	 * @param null|array<Section\AgreementItem> $items
	 */
	private function mapAgreementItems(?array $items): ?DtoCollection
	{
		if ($items === null)
		{
			return null;
		}

		$collection = new DtoCollection(Form\AgreementItemDto::class);
		foreach ($items as $item)
		{
			if (!$item instanceof Section\AgreementItem)
			{
				throw new ArgumentTypeException('items', Section\AgreementItem::class);
			}

			$dto = new Form\AgreementItemDto();
			$this->mapScalarFields(
				$dto,
				$item,
				[],
				[
					Section\AgreementItem::FIELD_AGREEMENT_ID,
					Section\AgreementItem::FIELD_CHECKED,
					Section\AgreementItem::FIELD_REQUIRED,
				],
			);
			$collection->add($dto);
		}

		return $collection;
	}

	private function mapSubmission(Section\Submission $submission, array $fields): Form\SubmissionDto
	{
		$dto = new Form\SubmissionDto();
		if (
			$this->isFieldSelected($fields, Section\Submission::FIELD_SUCCESS)
			&& $submission->success !== null
		)
		{
			$dto->success = $this->mapSubmissionResult($submission->success);
		}
		if (
			$this->isFieldSelected($fields, Section\Submission::FIELD_FAILURE)
			&& $submission->failure !== null
		)
		{
			$dto->failure = $this->mapSubmissionResult($submission->failure);
		}
		$this->mapScalarFields(
			$dto,
			$submission,
			$fields,
			[Section\Submission::FIELD_REDIRECT_DELAY],
		);
		if (
			$this->isFieldSelected($fields, Section\Submission::FIELD_REFILL)
			&& $submission->refill !== null
		)
		{
			$dto->refill = $this->mapSubmissionRefill($submission->refill);
		}

		return $dto;
	}

	private function mapSubmissionRefill(Section\SubmissionRefill $refill): Form\SubmissionRefillDto
	{
		$dto = new Form\SubmissionRefillDto();
		$this->mapScalarFields(
			$dto,
			$refill,
			[],
			[
				Section\SubmissionRefill::FIELD_ENABLED,
				Section\SubmissionRefill::FIELD_CAPTION,
			],
		);

		return $dto;
	}

	private function mapSubmissionResult(Section\SubmissionResult $result): Form\SubmissionResultDto
	{
		$dto = new Form\SubmissionResultDto();
		$this->mapScalarFields(
			$dto,
			$result,
			[],
			[
				Section\SubmissionResult::FIELD_URL,
				Section\SubmissionResult::FIELD_TEXT,
			],
		);

		return $dto;
	}

	private function mapPayment(Section\Payment $payment, array $fields): Form\PaymentDto
	{
		$dto = new Form\PaymentDto();
		$this->mapScalarFields(
			$dto,
			$payment,
			$fields,
			[
				Section\Payment::FIELD_ENABLED,
				Section\Payment::FIELD_DISABLED_PAY_SYSTEMS_ID,
			],
		);

		return $dto;
	}

	private function mapCallback(Section\Callback $callback, array $fields): Form\CallbackDto
	{
		$dto = new Form\CallbackDto();
		$this->mapScalarFields(
			$dto,
			$callback,
			$fields,
			[
				Section\Callback::FIELD_ENABLED,
				Section\Callback::FIELD_FROM,
				Section\Callback::FIELD_TEXT,
			],
		);

		return $dto;
	}

	private function mapBooking(Section\Booking $booking, array $fields): Form\BookingDto
	{
		$dto = new Form\BookingDto();
		$this->mapScalarFields(
			$dto,
			$booking,
			$fields,
			[Section\Booking::FIELD_ENABLED],
		);

		return $dto;
	}

	private function mapAnalytics(Section\Analytics $analytics, array $fields): Form\AnalyticsDto
	{
		$dto = new Form\AnalyticsDto();
		$this->mapScalarFields(
			$dto,
			$analytics,
			$fields,
			[
				Section\Analytics::FIELD_GOOGLE_ANALYTICS_ID,
				Section\Analytics::FIELD_GOOGLE_ANALYTICS_PAGE_VIEW_ENABLED,
				Section\Analytics::FIELD_YANDEX_METRICA_ID,
			],
		);

		return $dto;
	}

	private function mapSpamProtection(
		Section\SpamProtection $spamProtection,
		array $fields,
	): Form\SpamProtectionDto
	{
		$dto = new Form\SpamProtectionDto();
		$this->mapScalarFields(
			$dto,
			$spamProtection,
			$fields,
			[Section\SpamProtection::FIELD_ENABLED],
		);

		return $dto;
	}

	private function mapIntegrations(Section\Integrations $integrations, array $fields): Form\IntegrationsDto
	{
		$dto = new Form\IntegrationsDto();
		$this->mapScalarFields(
			$dto,
			$integrations,
			$fields,
			[Section\Integrations::FIELD_WHATS_APP_ENABLED],
		);

		if ($this->isFieldSelected($fields, Section\Integrations::FIELD_ADS))
		{
			$ads = $this->mapAdIntegrations($integrations->ads);
			if ($ads !== null)
			{
				$dto->ads = $ads;
			}
		}

		return $dto;
	}

	/**
	 * @param null|array<Section\AdIntegration> $items
	 */
	private function mapAdIntegrations(?array $items): ?DtoCollection
	{
		if ($items === null)
		{
			return null;
		}

		$collection = new DtoCollection(Form\AdIntegrationDto::class);
		foreach ($items as $item)
		{
			if (!$item instanceof Section\AdIntegration)
			{
				throw new ArgumentTypeException('ads', Section\AdIntegration::class);
			}

			$collection->add($this->mapAdIntegration($item));
		}

		return $collection;
	}

	private function mapAdIntegration(Section\AdIntegration $integration): Form\AdIntegrationDto
	{
		$dto = new Form\AdIntegrationDto();
		$this->mapScalarFields(
			$dto,
			$integration,
			[],
			[
				Section\AdIntegration::FIELD_DIRECTION,
				Section\AdIntegration::FIELD_PROVIDER_CODE,
				Section\AdIntegration::FIELD_ACCOUNT_ID,
				Section\AdIntegration::FIELD_ACCOUNT_NAME,
				Section\AdIntegration::FIELD_FORM_ID,
				Section\AdIntegration::FIELD_FORM_NAME,
			],
		);

		$fieldMappings = $this->mapAdFieldMappings($integration->fieldMappings);
		if ($fieldMappings !== null)
		{
			$dto->fieldMappings = $fieldMappings;
		}

		return $dto;
	}

	/**
	 * @param null|array<Section\AdFieldMapping> $items
	 */
	private function mapAdFieldMappings(?array $items): ?DtoCollection
	{
		if ($items === null)
		{
			return null;
		}

		$collection = new DtoCollection(Form\AdFieldMappingDto::class);
		foreach ($items as $item)
		{
			if (!$item instanceof Section\AdFieldMapping)
			{
				throw new ArgumentTypeException('fieldMappings', Section\AdFieldMapping::class);
			}

			$dto = new Form\AdFieldMappingDto();
			$this->mapScalarFields(
				$dto,
				$item,
				[],
				[
					Section\AdFieldMapping::FIELD_CRM_FIELD_NAME,
					Section\AdFieldMapping::FIELD_ADS_FIELD_NAME,
				],
			);
			$collection->add($dto);
		}

		return $collection;
	}

	/**
	 * @param string[] $fieldNames
	 */
	private function mapScalarFields(
		Dto $dto,
		object $source,
		array $selectedFields,
		array $fieldNames,
	): void
	{
		foreach ($fieldNames as $fieldName)
		{
			if (!$this->isFieldSelected($selectedFields, $fieldName))
			{
				continue;
			}

			$value = $source->{$fieldName};
			$field = $dto->getFields()[$fieldName];
			if ($value === null && !$field->isNullable())
			{
				continue;
			}

			$dto->{$fieldName} = $value;
		}
	}

	private function isFieldSelected(array $fields, string $fieldName): bool
	{
		return $fields === []
			|| in_array($fieldName, $fields, true)
			|| array_key_exists($fieldName, $fields)
		;
	}

	private function getNestedFields(array $fields, string $fieldName): array
	{
		$nestedFields = $fields[$fieldName] ?? null;

		return is_array($nestedFields) ? $nestedFields : [];
	}
}
