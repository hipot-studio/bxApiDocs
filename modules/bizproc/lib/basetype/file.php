<?php

namespace Bitrix\Bizproc\BaseType;

use Bitrix\Bizproc\Internal\Entity\Activity\SettingType;
use Bitrix\Bizproc\Internal\Integration\UI\TaskFileTileMetadata;
use Bitrix\Bizproc\Internal\Integration\UI\TaskFileUploaderContext;
use Bitrix\Main;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Class File
 * @package Bitrix\Bizproc\BaseType
 */
class File extends Base
{
	/**
	 * The id of the task the property belongs to, in the settings of that property. In a form its presence
	 * is the only sign of a task card: every other form of a file field keeps the file input. The REST
	 * layer names the task of a value it internalizes under the same key.
	 */
	public const SETTINGS_TASK_ID = 'TaskId';

	/**
	 * The code of the field, next to the task above. The task alone does not say which field of it a value
	 * belongs to, and a file saved out of that value is a file of that one field.
	 */
	public const SETTINGS_FIELD_NAME = 'FieldName';

	/**
	 * Names the task a card draws the field for. Both renders of the card - the one of the template and
	 * the re-render of the client - pass their fields through here, so the rule stays one.
	 */
	public static function fillTaskIdSetting(
		\CBPDocumentService $documentService,
		array $documentType,
		array $field,
		int $taskId
	): array
	{
		$fieldTypeObject = $documentService->getFieldTypeObject($documentType, $field);
		if ($fieldTypeObject?->getBaseType() !== FieldType::FILE)
		{
			return $field;
		}

		// the task being performed wins over a setting of the template, which names no task of its own
		$field['Settings'] = [self::SETTINGS_TASK_ID => $taskId] + ($field['Settings'] ?? []);

		return $field;
	}

	/**
	 * Tells the type which field of which task a value it is about to internalize belongs to, so that a
	 * file saved out of that value is remembered as a file of that field (rememberTaskFileSavedHere).
	 */
	public static function taskFieldSettings(int $taskId, string $fieldName): array
	{
		return [
			self::SETTINGS_TASK_ID => $taskId,
			self::SETTINGS_FIELD_NAME => $fieldName,
		];
	}

	/**
	 * @return string
	 */
	public static function getType()
	{
		return FieldType::FILE;
	}

	/**
	 * Get formats list.
	 * @return array
	 */
	public static function getFormats()
	{
		$formats = parent::getFormats();
		$formats['src'] = [
			'callable'  => 'formatValueSrc',
			'separator' => ', ',
		];

		$formats['publink'] = [
			'callable'  => 'formatValuePublicLink',
			'separator' => ', ',
		];

		$formats['shortlink'] = [
			'callable'  => 'formatValueShortLink',
			'separator' => ', ',
		];

		$formats['name'] = [
			'callable'  => 'formatValueName',
			'separator' => ', ',
		];

		return $formats;
	}

	/**
	 * The ids a value names when it is an id or a flat list of ids, null for any other shape:
	 * toSingleValue() reads the keys of an associative array as ids, so only this shape is checkable.
	 */
	public static function toIdList(mixed $value): ?array
	{
		$ids = is_array($value) ? $value : [$value];
		if (!array_is_list($ids))
		{
			return null;
		}

		foreach ($ids as $id)
		{
			if (!is_int($id) && !(is_string($id) && preg_match('/^[0-9]+$/D', $id) === 1))
			{
				return null;
			}
		}

		return array_map('strval', $ids);
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
			if (\CBPHelper::isAssociativeArray($value))
			{
				$value = array_keys($value);
			}
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
		$value = (int) $value;
		$iterator = \CFile::getByID($value);
		if ($file = $iterator->fetch())
		{
			return '[url=/bitrix/tools/bizproc_show_file.php?f='.urlencode($file['FILE_NAME']).'&hash='
				.md5($file['FILE_NAME'])
				.'&i='.$value.'&h='.md5($file['SUBDIR']).']'
				.htmlspecialcharsbx($file['ORIGINAL_NAME'])
				.'[/url]';
		}

		return '';
	}

	/**
	 * @param FieldType $fieldType
	 * @param $value
	 * @return string
	 */
	protected static function formatValueSrc(FieldType $fieldType, $value)
	{
		$value = (int) $value;
		$file = \CFile::getFileArray($value);
		if ($file && $file['SRC'])
		{
			return $file['SRC'];
		}
		return '';
	}

	/**
	 * @param FieldType $fieldType
	 * @param $value
	 * @return string
	 */
	protected static function formatValueName(FieldType $fieldType, $value)
	{
		$value = (int) $value;
		$file = \CFile::getFileArray($value);
		if ($file && ($file['ORIGINAL_NAME'] || $file['FILE_NAME']))
		{
			return $file['ORIGINAL_NAME'] ?: $file['FILE_NAME'];
		}
		return '';
	}

	/**
	 * @param FieldType $fieldType
	 * @param $value
	 * @return string
	 */
	protected static function formatValuePublicLink(FieldType $fieldType, $value)
	{
		$fileId = (int) $value;
		if ($fileId)
		{
			return \Bitrix\Bizproc\Controller\File::getPublicLink($fileId);
		}
		return '';
	}

	/**
	 * @param FieldType $fieldType
	 * @param $value
	 * @return string
	 */
	protected static function formatValueShortLink(FieldType $fieldType, $value)
	{
		$pubLink = static::formatValuePublicLink($fieldType, $value);
		if ($pubLink)
		{
			return Main\Engine\UrlManager::getInstance()->getHostUrl().\CBXShortUri::getShortUri($pubLink);
		}
		return '';
	}

	/**
	 * @param FieldType $fieldType Document field type.
	 * @param mixed $value Field value.
	 * @param string $toTypeClass Type class name.
	 * @return null
	 */
	public static function convertTo(FieldType $fieldType, $value, $toTypeClass)
	{
		/** @var Base $toTypeClass */
		$type = $toTypeClass::getType();
		switch ($type)
		{
			case FieldType::FILE:
				$value = (int) $value;
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
		return [
			[
				FieldType::FILE
			]
		];
	}

	/**
	 * @param FieldType $fieldType Document field type.
	 * @param mixed $value Field value.
	 * @param string $toTypeClass Type class name.
	 * @return array
	 */
	public static function convertValueMultiple(FieldType $fieldType, $value, $toTypeClass)
	{
		$value = (array) $value;
		if (\CBPHelper::isAssociativeArray($value))
		{
			$value = array_keys($value);
		}

		return parent::convertValueMultiple($fieldType, $value, $toTypeClass);
	}

	/**
	 * @param int $renderMode Control render mode.
	 * @return bool
	 */
	public static function canRenderControl($renderMode)
	{
		return true;
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
		if ($renderMode & FieldType::RENDER_MODE_DESIGNER)
		{
			return '';
		}

		$classNameHtml = htmlspecialcharsbx(static::generateControlClassName($fieldType, $field));
		$idHtml = htmlspecialcharsbx(static::generateControlId($field));
		$nameHtml = htmlspecialcharsbx(static::generateControlName($field));

		if ($renderMode & FieldType::RENDER_MODE_PUBLIC)
		{
			$msg = htmlspecialcharsbx(Loc::getMessage('BPDT_FILE_CHOOSE_FILE'));
			$onchange = 'this.nextSibling.textContent = BX.Bizproc.FieldType.File.parseLabel(this.value);';
			$onchange = htmlspecialcharsbx($onchange);

			return <<<HTML
				<div class="{$classNameHtml}">
					<span>
						<span class="webform-small-button">{$msg}</span>
					</span>
					<input type="file" id="{$idHtml}" name="{$nameHtml}" onchange="{$onchange}">
					<span class="bizproc-type-control-file-label"></span>
				</div>
HTML;
		}

		return '<input type="file" class="'.$classNameHtml.'" id="'.$idHtml.'" name="'.$nameHtml.'">';
	}

	/**
	 * @param FieldType $fieldType
	 * @param array $field
	 * @param mixed $value
	 * @param bool $allowSelection
	 * @param int $renderMode
	 * @return string
	 */
	public static function renderControlSingle(FieldType $fieldType, array $field, $value, $allowSelection, $renderMode)
	{
		if ($allowSelection && $renderMode & FieldType::RENDER_MODE_PUBLIC)
		{
			return self::renderPublicSelectableControlSingle($fieldType, $field, $value);
		}

		if ($renderMode & FieldType::RENDER_MODE_MOBILE)
		{
			return self::renderMobileControl($fieldType, $field, $value);
		}

		return
			self::renderTaskControl($fieldType, $field, $renderMode)
			?? parent::renderControlSingle($fieldType, $field, $value, $allowSelection, $renderMode)
		;
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
		if ($renderMode & FieldType::RENDER_MODE_DESIGNER && !$allowSelection)
		{
			return '';
		}

		if ($allowSelection && $renderMode & FieldType::RENDER_MODE_PUBLIC)
		{
			return self::renderPublicSelectableControlMultiple($fieldType, $field, $value);
		}

		if ($renderMode & FieldType::RENDER_MODE_MOBILE)
		{
			return self::renderMobileControl($fieldType, $field, $value);
		}

		if ($renderMode & FieldType::RENDER_MODE_DESIGNER)
		{
			if (!is_array($value) || is_array($value) && \CBPHelper::isAssociativeArray($value))
			{
				$value = [$value];
			}

			// need to show at least one control
			if (empty($value))
			{
				$value[] = null;
			}

			$controls = [];

			foreach ($value as $k => $v)
			{
				$singleField = $field;
				$singleField['Index'] = $k;
				$controls[] = parent::renderControlSingle($fieldType, $singleField, $v, $allowSelection, $renderMode);
			}

			return static::wrapCloneableControls($controls, static::generateControlName($field));
		}

		return
			self::renderTaskControl($fieldType, $field, $renderMode)
			?? parent::renderControlMultiple($fieldType, $field, $value, $allowSelection, $renderMode)
		;
	}

	private static function renderPublicSelectableControlSingle(FieldType $fieldType, array $field, $value)
	{
		$name = static::generateControlName($field);
		$className = static::generateControlClassName($fieldType, $field);
		$className = str_replace('file', 'file-selectable', $className);

		return sprintf(
			'<input type="text" class="%s" name="%s" value="%s" placeholder="%s" '
				. 'data-role="inline-selector-target" data-select-mode="replace" '
				. 'data-selector-type="file" data-property="%s"/>',
			htmlspecialcharsbx($className),
			htmlspecialcharsbx($name),
			htmlspecialcharsbx((string)$value),
			htmlspecialcharsbx($fieldType->getDescription()),
			htmlspecialcharsbx(Main\Web\Json::encode($fieldType->getProperty()))
		);
	}

	private static function renderPublicSelectableControlMultiple(FieldType $fieldType, array $field, $value)
	{
		if (!is_array($value) || is_array($value) && \CBPHelper::isAssociativeArray($value))
		{
			$value = [$value];
		}

		// need to show at least one control
		if (empty($value))
		{
			$value[] = null;
		}

		$controls = [];

		foreach ($value as $k => $v)
		{
			$singleField = $field;
			$singleField['Index'] = $k;
			$controls[] = static::renderPublicSelectableControlSingle(
				$fieldType,
				$singleField,
				$v
			);
		}

		return static::renderPublicMultipleWrapper($fieldType, $field, $controls);
	}

	/**
	 * The container the card mounts the uploader widget into, drawn instead of the file input when the
	 * property names a task. One container per field however many values it takes - the drop zone of the
	 * widget is one for the whole field, hence the multiple render is caught too.
	 *
	 * The container carries the name of the file the field holds and the link it is served by, so it is
	 * drawn for a performer of the task only; anybody else keeps the file input.
	 */
	private static function renderTaskControl(FieldType $fieldType, array $field, int $renderMode): ?string
	{
		if (!($renderMode & FieldType::RENDER_MODE_PUBLIC))
		{
			return null;
		}

		// one container per field, so neither its name nor its id carries the index of a value
		unset($field['Index']);

		$controlName = static::generateControlName($field);
		$taskContext = TaskFileUploaderContext::createFromTask(
			(int)($fieldType->getSettings()[self::SETTINGS_TASK_ID] ?? 0),
			$controlName
		);

		if ($taskContext === null || !$taskContext->isCurrentUserPerformer())
		{
			return null;
		}

		Main\UI\Extension::load('bizproc.task.file-field');

		$config = [
			'controller' => $taskContext->getUploaderController()->getName(),
			'controllerOptions' => $taskContext->getUploaderControllerOptions(),
			// the value is read by the name of the control and not by the code of the field; the brackets
			// of a multiple field are appended by the uploader itself
			'hiddenFieldName' => $controlName,
			'multiple' => $fieldType->isMultiple(),
			'required' => $fieldType->isRequired(),
			'files' => TaskFileTileMetadata::forFieldValue($taskContext->getFieldValue()),
		];

		$classNameHtml = htmlspecialcharsbx(static::generateControlClassName($fieldType, $field));
		$idHtml = htmlspecialcharsbx(static::generateControlId($field));
		$configHtml = htmlspecialcharsbx(Main\Web\Json::encode($config));

		return <<<HTML
			<div class="{$classNameHtml}" id="{$idHtml}" data-role="bp-task-file"
				data-testid="task-file-field" data-config="{$configHtml}"></div>
HTML;
	}

	private static function renderMobileControl(FieldType $fieldType, array $field, $value)
	{
		/** @var \CMain */
		global $APPLICATION;
		ob_start();
		$APPLICATION->IncludeComponent(
			'bitrix:main.file.input',
			'mobile',
			[
				'MODULE_ID' => 'bizproc',
				'CONTROL_ID' => static::generateControlId($field),
				'ALLOW_UPLOAD' => 'A',
				'INPUT_NAME' => static::generateControlName($field),
				'INPUT_VALUE' => $value,
				'MULTIPLE' => $fieldType->isMultiple() ? 'Y' : 'N'
			]
		);

		return ob_get_clean();
	}

	/**
	 * @param FieldType $fieldType
	 * @param array $field
	 * @param array $request
	 * @return null|int
	 */
	protected static function extractValue(FieldType $fieldType, array $field, array $request)
	{
		$value = parent::extractValue($fieldType, $field, $request);

		if (is_array($value) && !empty($value['name']) && !empty($value['tmp_name']))
		{
			if (!is_uploaded_file($value['tmp_name']))
			{
				$value = null;
				static::addError([
					'code'      => 'ErrorValue',
					'message'   => Loc::getMessage('BPDT_FILE_SECURITY_ERROR'),
					'parameter' => static::generateControlName($field),
				]);
			}
			else
			{
				if (!array_key_exists('MODULE_ID', $value) || $value['MODULE_ID'] == '')
					$value['MODULE_ID'] = 'bizproc';

				$value = \CFile::saveFile($value, 'bizproc_wf');
				if (!$value)
				{
					$value = null;
					static::addError([
						'code'      => 'ErrorValue',
						'message'   => Loc::getMessage('BPDT_FILE_INVALID'),
						'parameter' => static::generateControlName($field),
					]);
				}
			}
		}
		elseif (\CBPActivity::isExpression($value))
		{
			//It`s OK
		}
		elseif (is_numeric($value) && defined('BX_MOBILE'))
		{
			$file = \CFile::getById($value)->fetch();
			if (!$file || $file['MODULE_ID'] !== 'bizproc')
			{
				$value = null;
			}
		}
		elseif (is_string($value) && $value !== '')
		{
			$taskContext = TaskFileUploaderContext::createFromRequest(
				$request,
				static::generateFieldControlName($field)
			);

			if ($taskContext !== null)
			{
				$value = is_numeric($value)
					? static::acceptTaskFileId($taskContext, (int)$value)
					: static::resolveTaskFileToken($taskContext, $field, $value)
				;
			}
			else
			{
				// from mobile

				$unsignedValue = \CBPDocument::unSignParameters($value);
				if (isset($unsignedValue[0]) && is_numeric($unsignedValue[0]) && (int)$unsignedValue[0] > 0)
				{
					$value = (int)$unsignedValue[0];
				}
				else
				{
					$value = null;
				}
			}
		}
		else
		{
			$value = null;
		}

		return $value;
	}

	/** The name of the control of the field itself, the index of a multiple value left out. */
	private static function generateFieldControlName(array $field): string
	{
		unset($field['Index']);

		return static::generateControlName($field);
	}

	/**
	 * The one rule an id of a file of a task field is taken by, shared by both branches the value arrives
	 * through: the form of the card (extractValue above) and the fields of a decision
	 * (CBPRequestInformationActivity::prepareResponseFields). An id is taken from a performer of the task
	 * and only where it is a value of this field already, an upload of this field not pinned yet, or a
	 * file this request saved for this field (internalizeValue below). Any other id of the portal names a
	 * file the performer never had, and the link that serves it asks for no authorization.
	 */
	public static function acceptTaskFileId(TaskFileUploaderContext $taskContext, int $fileId): ?int
	{
		return static::acceptTaskFileIds($taskContext, [$fileId])[0] ?? null;
	}

	/**
	 * The same rule over a whole value at once: the ids that are no value of the field yet are asked of
	 * the uploads in one select instead of a select per value.
	 *
	 * Whose task it is comes first: the context may be built out of an id a caller took from a request -
	 * the settings form of an activity in the designer is read through extractValue() as well - so nothing
	 * about the field is told to anybody but a performer.
	 *
	 * @param int[] $fileIds
	 * @return int[] those of the ids the rule takes, in the order they were given
	 */
	public static function acceptTaskFileIds(TaskFileUploaderContext $taskContext, array $fileIds): array
	{
		if (!$taskContext->isCurrentUserPerformer())
		{
			return [];
		}

		$fieldValue = $taskContext->getFieldValue();
		$currentValues = is_array($fieldValue) ? $fieldValue : [$fieldValue];

		$current = [];
		foreach ($currentValues as $value)
		{
			if (is_numeric($value))
			{
				$current[(int)$value] = true;
			}
		}

		$saved = [];
		$candidates = [];
		foreach ($fileIds as $fileId)
		{
			$fileId = (int)$fileId;
			if ($fileId <= 0 || isset($current[$fileId]))
			{
				continue;
			}

			if ($taskContext->hasServerSavedFileId($fileId))
			{
				$saved[$fileId] = true;
			}
			else
			{
				$candidates[$fileId] = $fileId;
			}
		}

		$pending = $candidates
			? array_flip($taskContext->filterPendingFileIds(array_values($candidates)))
			: []
		;

		$accepted = [];
		foreach ($fileIds as $fileId)
		{
			$fileId = (int)$fileId;
			if (
				$fileId > 0
				&& (isset($current[$fileId]) || isset($saved[$fileId]) || isset($pending[$fileId]))
			)
			{
				$accepted[] = $fileId;
			}
		}

		return $accepted;
	}

	private static function resolveTaskFileToken(
		TaskFileUploaderContext $taskContext,
		array $field,
		string $token
	): ?int
	{
		$fileId = $taskContext->resolveToken($token);

		if ($fileId === null)
		{
			static::addError([
				'code' => 'ErrorValue',
				'message' => Loc::getMessage('BPDT_FILE_NOT_UPLOADED'),
				'parameter' => static::generateControlName($field),
			]);
		}

		return $fileId;
	}

	public static function externalizeValue(FieldType $fieldType, $context, $value)
	{
		if ($context === 'rest' && is_numeric($value))
		{
			return \CRestUtil::GetFile($value);
		}

		return parent::externalizeValue($fieldType, $context, $value);
	}

	public static function internalizeValue(FieldType $fieldType, $context, $value)
	{
		if ($context === 'rest')
		{
			$fileFields = \CRestUtil::saveFile($value);

			if ($fileFields)
			{
				$fileFields['MODULE_ID'] = 'bizproc';
				$fileId = (int) \CFile::saveFile($fileFields, 'bizproc_rest');
				static::rememberTaskFileSavedHere($fieldType, $fileId);

				return $fileId;
			}
		}

		return parent::internalizeValue($fieldType, $context, $value);
	}

	/**
	 * Remembers the file just saved out of the data of the request as a file of the field the property
	 * names (taskFieldSettings above). Nothing about the b_file record tells it from a file of anybody
	 * else, so what is remembered is that this request saved it for this field - what
	 * acceptTaskFileIds() asks. A property naming no task is remembered of nothing.
	 */
	private static function rememberTaskFileSavedHere(FieldType $fieldType, int $fileId): void
	{
		$settings = $fieldType->getSettings();

		TaskFileUploaderContext::registerServerSavedFileId(
			(int)($settings[self::SETTINGS_TASK_ID] ?? 0),
			(string)($settings[self::SETTINGS_FIELD_NAME] ?? ''),
			$fileId
		);
	}

	public static function getAiSettingType(): SettingType
	{
		return new SettingType(
			name: static::getType(),
			description: 'The value is numeric reference to ID of file in Bitrix24 database',
		);
	}

	public static function clearValueSingle(FieldType $fieldType, $value)
	{
		$file = \CFile::getByID($value)->fetch();
		if ($file && $file['MODULE_ID'] === 'bizproc')
		{
			\CFile::Delete($file['ID']);
		}
	}
}
