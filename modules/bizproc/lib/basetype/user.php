<?php
namespace Bitrix\Bizproc\BaseType;

use Bitrix\Bizproc\Internal\Entity\Activity\SettingType;
use Bitrix\HumanResources\Compatibility\Utils\DepartmentBackwardAccessCode;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Automation;
use COption;
use CSite;
use CUser;
use phpDocumentor\Reflection\Types\This;

/**
 * Class User
 * @package Bitrix\Bizproc\BaseType
 */
class User extends Base
{
	/**
	 * @return string
	 */
	public static function getType()
	{
		return FieldType::USER;
	}

	/**
	 * Get formats list.
	 * @return array
	 */
	public static function getFormats()
	{
		$formats = parent::getFormats();

		$userSpecificFormats = [
			'friendly' => [
				'callable' => 'formatValueFriendly',
				'separator' => ', ',
			],
			'bbcode' => [
				'callable' => 'formatValueBbcode',
				'separator' => ', ',
			],
		];

		return array_merge($formats, $userSpecificFormats);
	}

	/**
	 * Normalize single value.
	 *
	 * @param FieldType $fieldType Document field type.
	 * @param mixed $value Field value.
	 * @return mixed Normalized value
	 */
	public static function toSingleValue(FieldType $fieldType, $value)
	{
		if (is_array($value))
		{
			reset($value);
			$value = current($value);
		}

		return $value;
	}

	/**
	 * @param FieldType $fieldType
	 * @param $value
	 * @return string
	 */
	protected static function formatValuePrintable(FieldType $fieldType, $value)
	{
		if (!is_array($value))
			$value = array($value);

		return \CBPHelper::usersArrayToString($value, null, $fieldType->getDocumentType());
	}

	/**
	 * @param FieldType $fieldType
	 * @param $value
	 * @return string
	 */
	protected static function formatValueFriendly(FieldType $fieldType, $value)
	{
		if (!is_array($value))
		{
			$value = [$value];
		}

		return \CBPHelper::usersArrayToString($value, null, $fieldType->getDocumentType(), false);
	}

	protected static function formatValueBbcode(FieldType $fieldType, $value)
	{
		if (!is_array($value))
		{
			$value = [$value];
		}

		if (!Main\Loader::includeModule('im'))
		{
			return self::formatValueFriendly($fieldType, $value);
		}

		$formatFunction = static function(array $arUser) {
			if (empty($arUser['ID']))
			{
				return '';
			}

			$userId = (int)$arUser['ID'];
			$nameTemplate = COption::GetOptionString("bizproc", "name_template", CSite::GetNameFormat(false), SITE_ID);
			$innerText = (string)CUser::FormatName(
				$nameTemplate,
				$arUser,
				true,
				false,
			);

			return \Bitrix\Im\V2\Message\Text\BbCode\User::build($userId, $innerText)->compile();
		};

		return \CBPHelper::usersArrayToString(
			$value,
			null,
			$fieldType->getDocumentType(),
			false,
			$formatFunction,
		);
	}

	/**
	 * @param FieldType $fieldType Document field type.
	 * @param mixed $value Field value.
	 * @param string $toTypeClass Type class name.
	 * @return null|mixed
	 */
	public static function convertTo(FieldType $fieldType, $value, $toTypeClass)
	{
		/** @var Base $toTypeClass */
		$type = $toTypeClass::getType();
		switch ($type)
		{
			case FieldType::DOUBLE:
			case FieldType::INT:
				$value = (string)$value;
				if (mb_strpos($value, 'user_') === 0)
					$value = mb_substr($value, mb_strlen('user_'));
				$value = (int)$value;
				break;
			case FieldType::STRING:
			case FieldType::TEXT:
			case FieldType::USER:
				$value = (string)$value;
				break;
			default:
				$value = null;
		}

		return $value;
	}

	/**
	 * Return conversion map for current type.
	 * @return array Map.
	 */
	public static function getConversionMap()
	{
		return array(
			array(
				FieldType::DOUBLE,
				FieldType::INT,
				FieldType::STRING,
				FieldType::TEXT,
				FieldType::USER
			)
		);
	}

	/**
	 * @param FieldType $fieldType
	 * @param array $field
	 * @param mixed $value
	 * @param bool $allowSelection
	 * @param int $renderMode
	 * @return string
	 */
	protected static function renderControl(FieldType $fieldType, array $field, $value, $allowSelection, $renderMode)
	{
		if ($value !== null && !is_array($value))
		{
			if (self::isRawValue($value))
			{
				$errors = [];
				$value = \CBPHelper::UsersStringToArray($value, $fieldType->getDocumentType(), $errors);
			}
			else
			{
				$value = [$value];
			}
		}

		$isPublic = ($renderMode & FieldType::RENDER_MODE_PUBLIC);

		$valueString = \CBPHelper::usersArrayToString($value, null, $fieldType->getDocumentType());

		if ($allowSelection && !$isPublic)
		{
			return static::renderControlSelector($field, $valueString, 'combine', '', $fieldType);
		}

		if ($isPublic)
		{
			\CUtil::InitJSCore(['bp_user_selector']);
			$name = static::generateControlName($field);
			$controlId = static::generateControlId($field);

			$settings = $fieldType->getSettings();

			$config = [
				'valueInputName' => $name,
				'value' => $valueString,
				'items' => $value ? static::getSelectedItems($value, $settings) : [],
				'multiple' => $fieldType->isMultiple(),
				'required' => $fieldType->isRequired(),
				'canUseHumanResources' => static::canUseHumanResources(),
			];

			if ($settings)
			{
				$config += $settings;
			}

			$groups =
				\CBPRuntime::GetRuntime()
					->getDocumentService()
					->GetAllowableUserGroups($fieldType->getDocumentType(), true)
			;

			if ($groups)
			{
				$config['groups'] = [];
				foreach ($groups as $id => $groupName)
				{
					if (!$groupName || mb_strpos($id, 'group_') === 0)
					{
						continue;
					}

					$config['groups'][] = [
						'id' => preg_match('/^[0-9]+$/', $id) ? 'G'.$id : $id,
						'name' => $groupName
					];
				}
			}

			$controlIdJs = \CUtil::JSEscape($controlId);
			$controlIdHtml = htmlspecialcharsbx($controlId);
			$configHtml = htmlspecialcharsbx(Main\Web\Json::encode($config));
			$className = htmlspecialcharsbx(static::generateControlClassName($fieldType, $field));
			$property = $fieldType->getProperty();
			$property['Type'] = static::getType();
			$propertyHtml = htmlspecialcharsbx(Main\Web\Json::encode($property));

			return <<<HTML
				<script>
					BX.ready(function(){
						const c = document.getElementById('{$controlIdJs}');
						if (c)
						{
							BX.Bizproc.FieldType.initControl(c.parentNode, JSON.parse(c.dataset.property));
						}
					});
				</script>
				<div id="{$controlIdHtml}" data-role="user-selector" data-property="{$propertyHtml}" data-config="{$configHtml}" class="{$className}"></div>
HTML;
		}

		$renderResult = parent::renderControl($fieldType, $field, $valueString, $allowSelection, $renderMode);
		$renderResult .= static::renderControlSelector($field, null, false, '', $fieldType);

		return $renderResult;
	}

	/**
	 * @param FieldType $fieldType Document field type.
	 * @param array $field Form field.
	 * @param mixed $value Field value.
	 * @param bool $allowSelection Allow selection flag.
	 * @param int $renderMode Control render mode.
	 * @return string
	 */
	public static function renderControlSingle(FieldType $fieldType, array $field, $value, $allowSelection, $renderMode)
	{
		return static::renderControl($fieldType, $field, $value, $allowSelection, $renderMode);
	}

	/**
	 * @param FieldType $fieldType Document field type.
	 * @param array $field Form field.
	 * @param mixed $value Field value.
	 * @param bool $allowSelection Allow selection flag.
	 * @param int $renderMode Control render mode.
	 * @return string
	 */
	public static function renderControlMultiple(FieldType $fieldType, array $field, $value, $allowSelection, $renderMode)
	{
		return static::renderControl($fieldType, $field, $value, $allowSelection, $renderMode);
	}

	/**
	 * @param FieldType $fieldType Document field type.
	 * @param array $field Form field.
	 * @param array $request Request data.
	 * @return array|null
	 */
	protected static function extractValue(FieldType $fieldType, array $field, array $request)
	{
		$value = parent::extractValue($fieldType, $field, $request);
		$result = null;

		if (is_string($value) && $value !== '')
		{
			$errors = [];
			$result = \CBPHelper::usersStringToArray($value, $fieldType->getDocumentType(), $errors);
			if (sizeof($errors) > 0)
			{
				static::addErrors($errors);
			}
		}

		return $result;
	}

	/**
	 * @param FieldType $fieldType Document field type.
	 * @param array $field Form field.
	 * @param array $request Request data.
	 * @return null|string
	 */
	public static function extractValueSingle(FieldType $fieldType, array $field, array $request)
	{
		static::cleanErrors();
		$result = static::extractValue($fieldType, $field, $request);

		return is_array($result)? array_shift($result) : $result;
	}

	/**
	 * @param FieldType $fieldType Document field type.
	 * @param array $field Form field.
	 * @param array $request Request data.
	 * @return array|null
	 */
	public static function extractValueMultiple(FieldType $fieldType, array $field, array $request)
	{
		static::cleanErrors();
		return static::extractValue($fieldType, $field, $request);
	}

	public static function externalizeValue(FieldType $fieldType, $context, $value)
	{
		$useExtraction = $fieldType->getSettings()['ExternalExtract'] ?? false;

		if ($context === FieldType::VALUE_CONTEXT_JN_MOBILE)
		{
			$useExtraction = true;
		}

		if ($useExtraction && $value)
		{
			$docId = $fieldType->getDocumentId() ?: $fieldType->getDocumentType();

			return \CBPHelper::ExtractUsers($value, $docId, true);
		}

		return parent::externalizeValue($fieldType, $context, $value);
	}

	public static function externalizeValueMultiple(FieldType $fieldType, $context, $value)
	{
		$useExtraction = $fieldType->getSettings()['ExternalExtract'] ?? false;

		if ($context === FieldType::VALUE_CONTEXT_JN_MOBILE)
		{
			$useExtraction = true;
		}

		if ($useExtraction && $value)
		{
			$docId = $fieldType->getDocumentId() ?: $fieldType->getDocumentType();
			return \CBPHelper::ExtractUsers($value, $docId);
		}

		return parent::externalizeValueMultiple($fieldType, $context, $value);
	}

	public static function internalizeValue(FieldType $fieldType, $context, $value)
	{
		if ($context === FieldType::VALUE_CONTEXT_JN_MOBILE && is_numeric($value))
		{
			$value = 'user_' . $value;
		}

		return parent::internalizeValue($fieldType, $context, $value);
	}

	private static function getSelectedItems(array $value, array $settings = []): ?array
	{
		if (!class_exists(\Bitrix\UI\EntitySelector\Dialog::class))
		{
			return null;
		}

		$mapCallback = static function ($value)
		{
			if ($value && str_starts_with($value, 'user_'))
			{
				return ['user', \CBPHelper::StripUserPrefix($value)];
			}

			if ($value && str_starts_with($value, 'group_d'))
			{
				return ['department', preg_replace('|\D+|', '', $value)];
			}

			if ($value && str_starts_with($value, 'group_hr'))
			{
				return ['structure-node', preg_replace('|\D+|', '', $value)];
			}

			return null;
		};

		$preselectedItems = array_filter(array_map($mapCallback, $value));

		if (!$preselectedItems)
		{
			return [];
		}

		if (static::canUseHumanResources())
		{
			$nodeRepository = Container::getNodeRepository();
			foreach ($preselectedItems as $key => $item)
			{
				if ($item[0] === 'department')
				{
					$node = $nodeRepository->getByAccessCode(DepartmentBackwardAccessCode::makeById((int)$item[1]));
					if ($node)
					{
						unset($preselectedItems[$key]);
						$preselectedItems[] = ['structure-node', $node->id];
					}
				}
			}
		}

		$options = [];

		if (!empty($settings['allowEmailUsers']))
		{
			$options['entities'] = [
				[
					'id' => 'user',
					'options' => [
						'emailUsers' => true,
						'myEmailUsers' => true,
					]
				]
			];
		}

		return \Bitrix\UI\EntitySelector\Dialog::getPreselectedItems($preselectedItems, $options)->toArray();
	}

	public static function validateValueSingle($value, FieldType $fieldType)
	{
		$value = static::toSingleValue($fieldType, $value);

		$value = trim($value);

		$isUser = (mb_strpos($value, 'user_') !== false);
		if ($isUser)
		{
			return $value;
		}

		$isGroup = (mb_strpos($value, 'group_') !== false);
		if ($isGroup)
		{
			return $value;
		}

		$isNumber = preg_match('#^[0-9]+$#', $value);
		if ($isNumber)
		{
			return $value;
		}

		$isDocumentGroup = Automation\Helper::isDocumentUserGroup($value, $fieldType->getDocumentType());
		if ($isDocumentGroup)
		{
			return $value;
		}

		return null;
	}

	public static function validateValueMultiple($value, FieldType $fieldType): array
	{
		$value = parent::validateValueMultiple($value, $fieldType);
		$value = array_filter($value, static fn($v) => ($v !== null));

		return array_values(array_unique($value));
	}

	public static function convertPropertyToView(FieldType $fieldType, int $viewMode, array $property): array
	{
		if ($viewMode === FieldType::RENDER_MODE_JN_MOBILE && $fieldType->getValue())
		{
			$value = \CBPHelper::flatten($fieldType->getValue());
			$value = array_values(array_filter($value, fn($v) => strpos($v, 'user_') !== false));

			$property['Settings'] = ['entityList' => static::getSelectedItems($value)];
			$property['Type'] = static::getType();
		}

		return parent::convertPropertyToView($fieldType, $viewMode, $property);
	}

	private static function isRawValue($value): bool
	{
		return (
			is_string($value)
			&& !is_numeric($value)
			&& strpos($value, 'user_') === false
			&& strpos($value, 'group_') === false
		);
	}

	private static function canUseHumanResources(): bool
	{
		return Main\Loader::includeModule('humanresources');
	}

	public static function getAiSettingType(): SettingType
	{
		return new SettingType(
			name: static::getType(),
			description:
				'The values are references to users in Bitrix24. Four forms are accepted'
				. ' (see \CBPHelper::extractUsers).'
				. ' 1) A direct reference "user_{number}", where {number} is the numeric identifier of the user,'
				. ' for example "user_1" for the user with ID 1.'
				. ' 2) An expression "{=Document:FIELD}" pointing at a document field that holds users; it is'
				. ' resolved when the process runs, so it keeps working when the person changes. The fields'
				. ' available for a document type are listed by the document.field.list method - the ones of type'
				. ' "user" are the ones that fit here, and a field name may contain a dot, for example'
				. ' "{=Document:ASSIGNED_BY.UF_HEAD}" for the head of the responsible person.'
				. ' 3) A group code "group_{code}", for example "group_UA" for every employee, "group_D{id}" for a'
				. ' single department, "group_DR{id}" for that department together with all of its sub-departments,'
				. ' "group_G{id}" for a user group, "group_SG{id}_{A|E|K}" for a social network group. The only'
				. ' difference between "group_D{id}" and "group_DR{id}" is the sub-departments: both give every'
				. ' employee who belongs to the department, and neither of them singles out its head. No group code'
				. ' means "the head of a department" - a head is addressed by an expression on a field that holds'
				. ' one, as in form 2.'
				. ' 4) The literal "author", where the setting offers it as its default value: the document service'
				. ' resolves it to the person the document belongs to, so it only means anything for a block that'
				. ' works with a document that has one.'
				. ' There is no directory to look a person up by name.'
				. ' When the setting is REQUIRED and no specific person is named, look for a value the graph'
				. ' already offers before giving up: an earlier block may publish an output of this very type -'
				. ' the person who triggered the process, say - and the outputs of a written block are listed in'
				. ' its returnProperties. An expression on such an output is a real answer, not a guess.'
				. ' Only when nothing offers one, leave the setting out and say so in the answer instead of'
				. ' guessing a number - and check first whether anything downstream reads this block\'s outputs,'
				. ' because an unfilled required setting can cost the block those outputs.',
		);
	}
}
