<?php

use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Im\V2\Entity\User\User as UserV2;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPImOpenLinesMessageActivity extends CBPActivity
{
	const ATTACHMENT_TYPE_FILE = 'file';
	const ATTACHMENT_TYPE_DISK = 'disk';

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			"Title" => "",
			"MessageText" => "",
			"IsSystem" => "",

			'AttachmentType' => static::ATTACHMENT_TYPE_FILE,
			'Attachment' => [],
		];
	}

	public function Execute()
	{
		if (
			!\Bitrix\Main\Loader::includeModule("im")
			|| !\Bitrix\Main\Loader::includeModule("crm")
			|| !\Bitrix\Main\Loader::includeModule("imopenlines")
		)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		[$moduleId, $documentEntity, $documentId] = $this->GetDocumentId();

		if ($moduleId !== 'crm')
		{
			$this->writeError(GetMessage("IMOL_MA_UNSUPPORTED_DOCUMENT"));

			return CBPActivityExecutionStatus::Closed;
		}

		[$entityTypeId, $entityId] = \CCrmBizProcHelper::resolveEntityId($this->GetDocumentId());

		$fromUserId = \CCrmOwnerType::GetResponsibleID($entityTypeId, $entityId, false);

		if(UserV2::getInstance((int)$fromUserId)->isConnector())
		{
			$fromUserId = 0;
		}

		$sessionCode = $this->getSessionCodeByEntity($entityTypeId, $entityId);

		if (!$sessionCode)
		{
			$this->writeError(GetMessage("IMOL_MA_NO_SESSION_CODE"), $fromUserId);

			return CBPActivityExecutionStatus::Closed;
		}

		$messageText = (string)$this->MessageText;
		if ($messageText && mb_strpos($messageText, '<') !== false)
		{
			$messageText = HTMLToTxt($messageText);
		}


		$isSystem = ($this->IsSystem === 'Y');

		$chat = \Bitrix\Im\Model\ChatTable::getList(array(
			'filter' => array(
				'=ENTITY_TYPE' => 'LINES',
				'=ENTITY_ID' => $sessionCode
			),
			'limit' => 1
		))->fetch();

		if (!$chat)
		{
			$this->writeError(GetMessage("IMOL_MA_NO_CHAT"), $fromUserId);

			return CBPActivityExecutionStatus::Closed;
		}

		if ($messageText)
		{
			$messageFields = array(
				'FROM_USER_ID' => $fromUserId,
				'TO_CHAT_ID' => $chat['ID'],
				'MESSAGE' => $messageText,
				'NO_SESSION_OL' => 'Y',
			);

			if ($isSystem)
			{
				$messageFields['SILENT_CONNECTOR'] = 'Y';
			}
			else
			{
				$messageFields['PARAMS']['CLASS'] = "bx-messenger-content-item-ol-output";
			}

			$messageFields['SKIP_USER_CHECK'] = 'Y';

			$addResult = \Bitrix\ImOpenLines\Im::addMessage($messageFields);

			if ($addResult)
			{
				$this->sendAttachments($chat['ID'], $fromUserId);
			}
			else
			{
				/** @var \CMain $app*/
				$app = $GLOBALS["APPLICATION"];
				/** @var \CApplicationException $exception */
				$exception = $app->GetException();
				$this->writeError($exception->GetString(), $fromUserId);
			}
		}
		else
		{
			$this->sendAttachments($chat['ID'], $fromUserId);
		}

		if (method_exists(\CCrmBizProcHelper::class, 'sendOperationsAnalytics'))
		{
			// Send Operations Analytics
			$documentType = $this->getDocumentType();
			\CCrmBizProcHelper::sendOperationsAnalytics(
				Dictionary::EVENT_ENTITY_SOCIAL,
				$this,
				$documentType[2] ?? '',
			);
		}

		return CBPActivityExecutionStatus::Closed;
	}

	private function sendAttachments($chatId, $userId)
	{
		$rawAttachment = array_filter(static::flattenAttachmentValues(
			$this->getRawProperty('Attachment'),
			fn ($activityName) => $this->workflow->getActivityByName($activityName) instanceof \CBPActivity
		));

		if (!$rawAttachment)
		{
			return null;
		}

		$attachmentType = static::normalizeAttachmentType($this->AttachmentType);

		if ($attachmentType === null)
		{
			$this->writeAttachmentError(GetMessage('IMOL_MA_UNKNOWN_ATTACHMENT_TYPE'), $userId);

			return null;
		}

		$isDiskAttachments = ($attachmentType === static::ATTACHMENT_TYPE_DISK);

		if ($isDiskAttachments)
		{
			$requestedFiles = static::normalizeFileIds($this->Attachment);
			$allowedFiles = static::filterReadableDiskFiles($requestedFiles, (int)$this->getTemplateUserId());
			$refusalMessage = GetMessage('IMOL_MA_NO_ATTACHMENT_ACCESS');
		}
		else
		{
			[$requestedFiles, $allowedFiles] = $this->resolveMainFiles($rawAttachment);
			$refusalMessage = GetMessage('IMOL_MA_ATTACHMENT_NOT_ALLOWED');
		}

		if (!$requestedFiles)
		{
			$this->writeAttachmentError(GetMessage('IMOL_MA_NO_ATTACHMENT_RESOLVED'), $userId);

			return null;
		}

		if (!$allowedFiles)
		{
			$this->writeAttachmentError($refusalMessage, $userId);

			return null;
		}

		if ($isDiskAttachments)
		{
			$attachmentFiles = array_map(function ($file)
			{
				return 'disk'.$file;
			}, $allowedFiles);
		}
		else
		{
			$uploadedFiles = $this->uploadFilesFromMain($chatId, $allowedFiles);
			$attachmentFiles = is_array($uploadedFiles)
				? array_map(function ($file)
				{
					return 'upload'.$file;
				}, $uploadedFiles)
				: []
			;
		}

		$uploadResult = $attachmentFiles ? $this->attachFilesToChat($chatId, $attachmentFiles, $userId) : false;

		// the number of files the settings ask for is compared with what the chat really got: a file of the
		// document can be gone from b_file, and a file of Disk can be deleted after the robot was set up.
		// CIMDisk drops both without a word, so counting the refusals before the upload would lie
		$sentCount = is_array($uploadResult) ? count($uploadResult['DISK_ID'] ?? []) : 0;

		if ($sentCount <= 0)
		{
			$this->writeAttachmentError(GetMessage('IMOL_MA_ATTACHMENT_UPLOAD_FAILED'), $userId);

			return false;
		}

		$skippedCount = count($requestedFiles) - $sentCount;

		if ($skippedCount > 0)
		{
			$this->writeWarning(GetMessage('IMOL_MA_ATTACHMENTS_SKIPPED', ['#COUNT#' => $skippedCount]));
		}

		return true;
	}

	/**
	 * The two calls that reach the chat live apart from the counting above: what CIMDisk really attached
	 * is the only honest source for the journal, and a test has no live chat to ask.
	 */
	protected function uploadFilesFromMain($chatId, array $fileIds)
	{
		return \CIMDisk::UploadFileFromMain($chatId, $fileIds);
	}

	protected function attachFilesToChat($chatId, array $files, $userId)
	{
		// the attachments are a send of their own: they cannot carry NO_SESSION_OL of the text above,
		// so they take the mark of a robot send that the open line automation reads
		return \CIMDisk::UploadFileFromDisk($chatId, $files, '', [
			'USER_ID' => $userId,
			'SKIP_USER_CHECK' => true,
			'LINES_SILENT_MODE' => $this->IsSystem === 'Y',
			'PARAMS' => [\Bitrix\ImOpenLines\MessageParameter::OL_ROBOT_SEND => 'Y'],
		]);
	}

	/**
	 * Ids of the b_file branch: the whole set the template asks for, and the subset allowed to be sent.
	 *
	 * The shape of a value proves nothing: '=9999' is an expression as well, and a variable or a constant
	 * of the template is filled in by whoever edits the robot. Only the origin of the resolved file counts,
	 * so an id passes when the value names a file field of the document the process runs on and that field
	 * really holds the id.
	 *
	 * @return array{0: int[], 1: int[]}
	 */
	private function resolveMainFiles(array $rawValues): array
	{
		/** @var CBPDocumentService $documentService */
		$documentService = $this->workflow->getService('DocumentService');
		$documentType = $this->getDocumentType();
		$documentFields = $documentService->getDocumentFields($documentType);
		$fieldAliases = \CBPDocument::getDocumentFieldsAliasesMap($documentFields);
		// the same arguments the engine itself resolves a document field with, cache of the service included
		$usedDocumentFields = (array)($this->getRootActivity()->{CBPDocument::PARAM_USED_DOCUMENT_FIELDS} ?? []);

		$fieldNames = [];
		$pairValues = [];

		foreach ($rawValues as $index => $rawValue)
		{
			$fieldNames[$index] = static::resolveDocumentFileField($documentFields, $fieldAliases, $rawValue);
			$pairValues[$index] = static::parseDocumentFieldPair($rawValue) !== null;
		}

		// the fields are asked for by name: usages are collected from expressions only, so a value written
		// as the pair ['Document', 'FIELD'] leaves its field out of the selection of the document and both
		// the engine and the check below would see it empty. One selection for every field of the settings
		// keeps the element loaded once: its composition is part of the cache key of the document service.
		$selection = array_values(array_unique(array_merge(
			$usedDocumentFields,
			array_filter($fieldNames, 'is_string')
		)));

		$requestedFiles = [];
		$allowedFiles = [];
		$fieldFiles = [];

		foreach ($rawValues as $index => $rawValue)
		{
			$fieldName = $fieldNames[$index];
			$fileIds = static::normalizeFileIds($this->ParseValue($rawValue, 'file'));

			if ($fieldName === null)
			{
				// a result of another robot of the same template is not written by whoever edits the
				// settings: the engine fills it in from what that robot returned while the process was
				// running, so its files go through as they are
				if ($this->isWorkflowActivityFileResult($rawValue))
				{
					$allowedFiles = array_merge($allowedFiles, $fileIds);
				}

				$requestedFiles = array_merge($requestedFiles, $fileIds);

				continue;
			}

			$fieldFiles[$fieldName] ??= static::normalizeFileIds(
				$documentService->getFieldValue($this->getDocumentId(), $fieldName, $documentType, $selection)
			);

			// the pair is the field itself, so its own content is what the engine resolves it to. A string
			// expression is left alone: '{=Document:FIELD>src}' resolves to a printable value on purpose,
			// and the files of the field are not what it asks for
			if (!$fileIds && $pairValues[$index])
			{
				$fileIds = $fieldFiles[$fieldName];
			}
			$requestedFiles = array_merge($requestedFiles, $fileIds);
			$allowedFiles = array_merge($allowedFiles, array_intersect($fileIds, $fieldFiles[$fieldName]));
		}

		return [
			array_values(array_unique($requestedFiles)),
			array_values(array_unique($allowedFiles)),
		];
	}

	/**
	 * Name of the file field of the document the value points at, or null for everything else: a bare id, a
	 * calculated expression and a value the template itself provides are not files of the document.
	 */
	private static function resolveDocumentFileField(array $documentFields, array $fieldAliases, $rawValue): ?string
	{
		$parsed = static::parseExpression($rawValue) ?? static::parseDocumentFieldPair($rawValue);

		if (!$parsed || $parsed['object'] !== \Bitrix\Bizproc\Workflow\Template\SourceType::DocumentField)
		{
			return null;
		}

		$fieldName = (string)$parsed['field'];

		if (!isset($documentFields[$fieldName]))
		{
			$fieldName = (string)($fieldAliases[$fieldName] ?? '');
		}

		if (!static::isFileProperty($documentFields[$fieldName] ?? null))
		{
			return null;
		}

		return $fieldName;
	}

	private static function isFileProperty($property): bool
	{
		return is_array($property)
			&& (($property['Type'] ?? '') === 'file' || ($property['BaseType'] ?? '') === 'file');
	}

	/**
	 * A document field is referenced by the pair ['Document', 'FIELD'] as well as by the string
	 * '{=Document:FIELD}': the engine resolves both in CBPActivity::getPropertyValueRecursive(), and
	 * CBPHelper::makeArrayFlat() keeps such a pair intact instead of flattening it. Only the document
	 * object is trusted here — a pair naming a variable or a template carries a value of the template.
	 */
	private static function parseDocumentFieldPair($rawValue): ?array
	{
		if (
			!is_array($rawValue)
			|| count($rawValue) !== 2
			|| !isset($rawValue[0], $rawValue[1])
			|| $rawValue[0] !== \Bitrix\Bizproc\Workflow\Template\SourceType::DocumentField
			|| !is_string($rawValue[1])
		)
		{
			return null;
		}

		return [
			'object' => $rawValue[0],
			'field' => $rawValue[1],
		];
	}

	/**
	 * Values of the settings, flattened the way the engine resolves them.
	 *
	 * CBPHelper::makeArrayFlat() keeps whole only a pair of the six sources it knows by name, while
	 * CBPActivity::getPropertyValueRecursive() resolves any pair [object, field] — so a reference to a result
	 * of an activity written as the pair ['A42_11', 'DocumentPdf'] would fall apart into two strings and lose
	 * the file it names. Which pairs are references is asked of $knowsActivity: the process knows its
	 * activities by name at run time, the template names them at the form.
	 */
	private static function flattenAttachmentValues($value, ?callable $knowsActivity = null): array
	{
		if (!is_array($value))
		{
			return (is_string($value) && trim($value) === '') ? [] : [$value];
		}

		if (static::isReferencePair($value, $knowsActivity))
		{
			return [$value];
		}

		$values = [];

		foreach ($value as $item)
		{
			foreach (static::flattenAttachmentValues($item, $knowsActivity) as $flat)
			{
				$values[] = $flat;
			}
		}

		return $values;
	}

	/**
	 * The shape the engine reads as the pair [object, field].
	 *
	 * Two strings alone are not enough to tell one: the settings hold a list of values, so ['{=Document:FILE}',
	 * '=1078'] is a pair of attachments just as well, and ['1077', '2050'] is a pair of bare ids of b_file.
	 * The object is what tells them apart — either a source makeArrayFlat() itself knows by name, or an
	 * activity that really exists where the value is being read.
	 */
	private static function isReferencePair($value, ?callable $knowsActivity = null): bool
	{
		if (!static::isStringPair($value))
		{
			return false;
		}

		$knownSources = ['Variable', 'Document', 'Template', 'Workflow', 'User', 'System'];

		if (in_array($value[0], $knownSources, true))
		{
			return true;
		}

		return $knowsActivity !== null && $knowsActivity($value[0]);
	}

	private static function isStringPair($value): bool
	{
		return is_array($value)
			&& count($value) === 2
			&& isset($value[0], $value[1])
			&& is_string($value[0])
			&& is_string($value[1]);
	}

	/**
	 * Tells a name of an activity of the template being saved.
	 *
	 * The classic designer hands the form the whole template, so its activities are there to be found. The
	 * designer of automation hands over one robot alone (Automation\Engine\Template::saveRobotSettings()
	 * does setRobots([$robot])), and a result of a neighbour is a value it offers itself — hence the name it
	 * generates by the pattern Automation\Helper::convertExpressions() reads an expression of a result back
	 * with. Neither accepts an id of b_file: none of them looks like a name of an activity.
	 */
	private static function activityNameChecker($workflowTemplate): callable
	{
		$names = [];
		$walk = static function ($node) use (&$walk, &$names): void
		{
			if (!is_array($node))
			{
				return;
			}

			if (isset($node['Name']) && is_string($node['Name']))
			{
				$names[$node['Name']] = true;
			}

			foreach ((array)($node['Children'] ?? []) as $child)
			{
				$walk($child);
			}
		};

		foreach ((array)$workflowTemplate as $node)
		{
			$walk($node);
		}

		return static fn ($activityName) =>
			isset($names[$activityName]) || (bool)preg_match('/^A[_0-9]+$/', $activityName);
	}

	/**
	 * Name of the robot and of its result the value points at, or null for everything else.
	 *
	 * The designer offers results of other robots right in the file selector (bizproc, template.js), so
	 * refusing them would take away a working setup: an earlier robot generates a document, this one sends
	 * it to the client. Such a value is safe for its own reason — it is not written by whoever edits the
	 * settings at all, the engine fills it in while the process runs. Everything an editor does fill in — a
	 * variable, a constant, a parameter of the template — names a source of its own and stays out.
	 */
	private static function parseActivityResultExpression($rawValue): ?array
	{
		if (is_array($rawValue))
		{
			return static::parseActivityResultPair($rawValue);
		}

		$parsed = static::parseExpression($rawValue);

		if (!$parsed || !empty($parsed['modifiers']))
		{
			return null;
		}

		return static::asActivityResult((string)($parsed['object'] ?? ''), (string)($parsed['field'] ?? ''));
	}

	/**
	 * The same reference written as the pair ['A42_11', 'DocumentPdf'] — the shape the designer keeps in the
	 * settings of a robot and the engine resolves alongside the string expression.
	 */
	private static function parseActivityResultPair($rawValue): ?array
	{
		if (!static::isStringPair($rawValue))
		{
			return null;
		}

		return static::asActivityResult($rawValue[0], $rawValue[1]);
	}

	private static function asActivityResult(string $object, string $field): ?array
	{
		$ownSources = [
			\Bitrix\Bizproc\Workflow\Template\SourceType::DocumentField,
			\Bitrix\Bizproc\Workflow\Template\SourceType::Parameter,
			\Bitrix\Bizproc\Workflow\Template\SourceType::Variable,
			\Bitrix\Bizproc\Workflow\Template\SourceType::Constant,
			\Bitrix\Bizproc\Workflow\Template\SourceType::GlobalConstant,
			\Bitrix\Bizproc\Workflow\Template\SourceType::GlobalVariable,
			\Bitrix\Bizproc\Workflow\Template\SourceType::System,
			\Bitrix\Bizproc\Workflow\Template\SourceType::Input,
			\Bitrix\Bizproc\Workflow\Template\SourceType::CalcFunction,
			'Workflow',
			'User',
			'Template',
		];

		if ($object === '' || $field === '' || in_array($object, $ownSources, true))
		{
			return null;
		}

		return ['object' => $object, 'field' => $field];
	}

	/**
	 * The robot the result belongs to is not in the template the form gets: Automation\Engine\Template
	 * saves the settings of one robot and hands over a template made of that robot alone, so its neighbours
	 * cannot be asked about here. The form therefore only tells a result of a robot from a source the editor
	 * fills in himself; that the robot exists and really returns a file is checked where it matters — on the
	 * run, in resolveMainFiles(), where the whole process is at hand.
	 */
	private static function isActivityResultExpression($rawValue): bool
	{
		return static::parseActivityResultExpression($rawValue) !== null;
	}

	private function isWorkflowActivityFileResult($rawValue): bool
	{
		$parsed = static::parseActivityResultExpression($rawValue);

		if (!$parsed)
		{
			return false;
		}

		$activity = $this->workflow->getActivityByName($parsed['object']);

		if (!$activity instanceof \CBPActivity || $activity instanceof \CBPRestActivity)
		{
			// a robot of an application names its own result itself: CBPRestActivity::onExternalEvent()
			// writes down what the application sent, and a value of the type file goes in as it came —
			// File::internalizeValue() converts nothing outside the context 'rest'. Such a result proves
			// no origin at all, so a bare id of b_file would come back through it
			return false;
		}

		return static::isFileProperty(
			static::getDeclaredReturnProperties($activity)[$parsed['field']] ?? null
		);
	}

	/**
	 * Results the activity declares a file in its own description.
	 *
	 * The type the activity carries at run time is not the one to ask: it says what the value is, not where
	 * it is from. CBPForEachActivity::TryNextIteration() copies the type of the collection it walks into its
	 * own result 'Value' (setPropertiesTypes()) — so a constant of the type file holding a chosen id of
	 * b_file makes getPropertyType('Value') answer 'file' while the value stays the one the template holds.
	 * The description of the activity says something else: it is written by whoever wrote the activity, the
	 * template cannot reach it, and it declares a file only where the code of the activity makes the file
	 * itself. ForEach declares 'Value' as 'mixed' there and stays out; the robot generating a document of
	 * CRM declares 'DocumentPdf' and 'DocumentDocx' as files and goes through. The results a template adds
	 * to the description of an activity (ADDITIONAL_RESULT) are left out: they are asked for by the code of
	 * the activity alone, without the properties of the template.
	 */
	protected static function getDeclaredReturnProperties(\CBPActivity $activity): array
	{
		return \CBPRuntime::getRuntime()->getActivityReturnProperties(get_class($activity));
	}

	/**
	 * Disk objects a given employee is allowed to send: the form asks for the one who chooses the file, the
	 * runtime asks for the author of the template. Both are needed — the author of the template is rewritten
	 * by whoever saves the robots next, so only the check of the form keeps a file nobody could read out of
	 * the settings in the first place.
	 */
	/**
	 * The settings, checked on every entry into a template — the form aside.
	 *
	 * CBPWorkflowTemplateLoader::validateTemplate() calls this on every save and every import of a template:
	 * REST, the transport of the designer, the converter. GetPropertiesDialogValues() answers the form alone,
	 * so without this a template written past the form carries ids of Disk nobody proved a right to — and the
	 * author of the template, whom the run asks about those rights, drifts to whoever saves the template
	 * next. A file of Disk is named by its id right in the settings, so it can be answered for here; values
	 * of the other kind name a field or a result and are answered for on the run, where the process is at
	 * hand.
	 *
	 * Nobody to ask means nothing to check: a system import carries no user at all, and such a template is
	 * refused at the run instead — it has no author to read the file.
	 */
	public static function ValidateProperties($arTestProperties = [], ?CBPWorkflowTemplateUser $user = null)
	{
		$errors = [];
		$editorId = $user ? (int)$user->getId() : 0;

		if (
			$editorId > 0
			&& static::normalizeAttachmentType($arTestProperties['AttachmentType'] ?? '')
				=== static::ATTACHMENT_TYPE_DISK
		)
		{
			$requestedFiles = static::normalizeFileIds($arTestProperties['Attachment'] ?? []);
			$allowedFiles = static::filterReadableDiskFiles($requestedFiles, $editorId);

			if (count($allowedFiles) !== count($requestedFiles))
			{
				$errors[] = [
					'code' => 'ErrorValue',
					'message' => GetMessage('IMOL_MA_NO_ATTACHMENT_ACCESS'),
					'parameter' => 'attachment',
				];
			}
		}

		return array_merge($errors, parent::ValidateProperties($arTestProperties, $user));
	}

	private static function filterReadableDiskFiles(array $fileIds, int $userId): array
	{
		$fileIds = static::normalizeFileIds($fileIds);

		if (!$fileIds || $userId <= 0 || !\Bitrix\Main\Loader::includeModule('disk'))
		{
			return [];
		}

		$objectModels = [];

		foreach (\Bitrix\Disk\BaseObject::loadBatchById($fileIds, ['STORAGE']) as $objectModel)
		{
			$objectModels[(int)$objectModel->getId()] = $objectModel;
		}

		$securityContexts = [];
		$readableFiles = [];

		foreach ($fileIds as $fileId)
		{
			$fileModel = $objectModels[$fileId] ?? null;

			if (!$fileModel instanceof \Bitrix\Disk\File)
			{
				continue;
			}

			// an object moved to the trash is still loaded by its id and still passes the rights check,
			// while CIMDisk copies its content as it is. A link lives only as long as its real object
			$realFileModel = $fileModel->getRealObject();

			if (
				!$realFileModel instanceof \Bitrix\Disk\File
				|| $fileModel->isDeleted()
				|| $realFileModel->isDeleted()
			)
			{
				continue;
			}

			$storageModel = $fileModel->getStorage();

			if (!$storageModel)
			{
				continue;
			}

			$storageId = (int)$storageModel->getId();
			$securityContexts[$storageId] ??= $storageModel->getSecurityContext($userId);

			if (!$fileModel->canRead($securityContexts[$storageId]))
			{
				continue;
			}

			$readableFiles[] = $fileId;
		}

		return $readableFiles;
	}

	private static function normalizeFileIds($values): array
	{
		$fileIds = [];

		foreach (CBPHelper::MakeArrayFlat((array)$values) as $value)
		{
			// a pair like ['Document', 'FIELD'] is kept whole by makeArrayFlat(), and the engine gives it
			// back unresolved when the field it names does not exist. Casting it would read as the file 1
			if (!is_scalar($value))
			{
				continue;
			}

			$fileId = (int)$value;

			if ($fileId > 0)
			{
				$fileIds[] = $fileId;
			}
		}

		return array_values(array_unique($fileIds));
	}

	/**
	 * An empty value means a template saved before the setting appeared, everything else is either a known
	 * location or nothing at all.
	 */
	private static function normalizeAttachmentType($value): ?string
	{
		$value = (string)$value;

		if ($value === '')
		{
			return static::ATTACHMENT_TYPE_FILE;
		}

		$allowedTypes = [static::ATTACHMENT_TYPE_FILE, static::ATTACHMENT_TYPE_DISK];

		return in_array($value, $allowedTypes, true) ? $value : null;
	}

	public static function GetPropertiesDialog($documentType, $activityName, $workflowTemplate, $workflowParameters, $workflowVariables, $currentValues = null, $formName = "")
	{
		$dialog = new \Bitrix\Bizproc\Activity\PropertiesDialog(__FILE__, array(
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $workflowTemplate,
			'workflowParameters' => $workflowParameters,
			'workflowVariables' => $workflowVariables,
			'currentValues' => $currentValues
		));

		$dialog->setMap([
			'MessageText' => [
				'Name' => GetMessage('IMOL_MA_MESSAGE'),
				'FieldName' => 'message_text',
				'Type' => 'text',
				'Required' => true,
			],
			'IsSystem' => [
				'Name' => GetMessage('IMOL_MA_IS_SYSTEM'),
				'Description' => GetMessage('IMOL_MA_IS_SYSTEM_DESCRIPTION'),
				'FieldName' => 'is_system',
				'Type' => 'bool',
				'Default' => 'N',
			],
			'AttachmentType' => [
				'Name' => GetMessage('IMOL_MA_ATTACHMENT_TYPE_1'),
				'FieldName' => 'attachment_type',
				'Type' => 'select',
				'Options' => array(
					static::ATTACHMENT_TYPE_FILE => GetMessage('IMOL_MA_ATTACHMENT_FILE_2'),
					static::ATTACHMENT_TYPE_DISK => GetMessage('IMOL_MA_ATTACHMENT_DISK')
				),
			],
			'Attachment' => [
				'Name' => GetMessage('IMOL_MA_ATTACHMENT_1'),
				'FieldName' => 'attachment',
				'Type' => 'file',
				'Multiple' => true,
			],
		]);

		return $dialog;
	}

	public static function GetPropertiesDialogValues($documentType, $activityName, &$workflowTemplate, &$arWorkflowParameters, &$arWorkflowVariables, $currentValues, &$errors)
	{
		$errors = [];
		$currentUser = new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);
		$attachmentType = static::normalizeAttachmentType($currentValues["attachment_type"] ?? '');

		if ($attachmentType === null)
		{
			$errors[] = [
				'code' => 'ErrorValue',
				'message' => GetMessage('IMOL_MA_UNKNOWN_ATTACHMENT_TYPE'),
				'parameter' => 'attachment_type',
			];
			$attachmentType = static::ATTACHMENT_TYPE_FILE;
		}

		$properties = [
			'MessageText' => (string)$currentValues['message_text'],
			'IsSystem' => $currentValues['is_system'] === 'Y' ? 'Y' : 'N',
			'AttachmentType' => $attachmentType,
			'Attachment' => []
		];

		if ($properties['AttachmentType'] === static::ATTACHMENT_TYPE_DISK)
		{
			$fileIds = static::normalizeFileIds($currentValues["attachment"] ?? []);
			$readableFiles = static::filterReadableDiskFiles($fileIds, (int)$currentUser->getId());

			if (count($readableFiles) !== count($fileIds))
			{
				$errors[] = [
					'code' => 'ErrorValue',
					'message' => GetMessage('IMOL_MA_NO_ATTACHMENT_ACCESS'),
					'parameter' => 'attachment',
				];
			}

			$properties['Attachment'] = $readableFiles;
		}
		else
		{
			$attachment = $currentValues["attachment"] ?? $currentValues["attachment_text"] ?? [];
			$values = array_values(array_filter(static::flattenAttachmentValues(
				$attachment,
				static::activityNameChecker($workflowTemplate)
			)));
			$documentFields = \CBPRuntime::getRuntime()->getDocumentService()->getDocumentFields($documentType);
			$fieldAliases = \CBPDocument::getDocumentFieldsAliasesMap($documentFields);
			$allowedValues = [];

			foreach ($values as $value)
			{
				if (
					static::resolveDocumentFileField($documentFields, $fieldAliases, $value) !== null
					|| static::isActivityResultExpression($value)
				)
				{
					$allowedValues[] = $value;
				}
			}

			if (count($allowedValues) !== count($values))
			{
				$errors[] = [
					'code' => 'ErrorValue',
					'message' => GetMessage('IMOL_MA_ATTACHMENT_NOT_ALLOWED_FORM'),
					'parameter' => 'attachment',
				];
			}

			$properties['Attachment'] = $allowedValues;
		}

		$errors = array_merge(
			$errors,
			self::ValidateProperties($properties, $currentUser)
		);
		if (count($errors) > 0)
			return false;

		$currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($workflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
	}

	private function getSessionCodeByEntity($entityTypeId, $entityId)
	{
		$code = false;
		$lowPriorityCode = false;

		if ($entityTypeId === \CCrmOwnerType::Deal)
		{
			$clients = $this->getDealClients($entityId);
		}
		elseif ($entityTypeId === \CCrmOwnerType::Lead)
		{
			$clients = $this->getLeadClients($entityId);
		}
		elseif ($entityTypeId === \CCrmOwnerType::Order)
		{
			$clients = $this->getOrderClients($entityId);
		}
		elseif ($entityTypeId !== \CCrmOwnerType::Contact && CCrmOwnerType::isUseFactoryBasedApproach($entityTypeId))
		{
			$clients = $this->getItemClients($entityTypeId, $entityId);
		}
		else
		{
			$clients = [[\CCrmOwnerType::ResolveName($entityTypeId), $entityId]];
		}

		foreach ($clients as [$typeName, $id])
		{
			$iterator = \CCrmFieldMulti::GetList(
				array('ID' => 'desc'),
				array('ENTITY_ID' => $typeName, 'ELEMENT_ID' => $id, 'TYPE_ID' => \CCrmFieldMulti::IM)
			);

			while ($row = $iterator->fetch())
			{
				if (mb_strpos($row['VALUE'], 'imol|') === false)
				{
					continue;
				}

				$code = mb_substr($row['VALUE'], 5);

				if (mb_strpos($code, 'livechat') === 0)
				{
					$lowPriorityCode = $code;
					$code = false;
					continue;
				}

				break 2;
			}
		}

		if (!$code && $lowPriorityCode)
		{
			$code = $lowPriorityCode;
		}

		return $code;
	}

	private function getDealClients($dealId)
	{
		$clients = array();
		$deal = \CCrmDeal::GetByID($dealId, false);
		if($deal)
		{
			$dealContactId = isset($deal['CONTACT_ID']) ? intval($deal['CONTACT_ID']) : 0;
			$dealCompanyID = isset($deal['COMPANY_ID']) ? intval($deal['COMPANY_ID']) : 0;

			$dealContactIds = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs($dealId);

			if ($dealContactId > 0)
			{
				$clients[] = [\CCrmOwnerType::ContactName, $dealContactId];
			}
			if ($dealCompanyID > 0)
			{
				$clients[] = [\CCrmOwnerType::CompanyName, $dealCompanyID];
			}

			if ($dealContactIds)
			{
				foreach ($dealContactIds as $id)
				{
					if ($id !== $dealContactId)
					{
						$clients[] = [\CCrmOwnerType::ContactName, $id];
					}
				}
			}
		}
		return $clients;
	}

	private function getOrderClients($id)
	{
		$clients = [];

		$dbRes = \Bitrix\Crm\Order\ContactCompanyCollection::getList(array(
			'select' => array('ENTITY_ID', 'ENTITY_TYPE_ID'),
			'filter' => array(
				'=ORDER_ID' => $id,
				'@ENTITY_TYPE_ID' => [\CCrmOwnerType::Contact, \CCrmOwnerType::Company],
				'IS_PRIMARY' => 'Y'
			),
			'order' => ['ENTITY_TYPE_ID' => 'ASC']
		));
		while ($row = $dbRes->fetch())
		{
			$clients[] = [CCrmOwnerType::ResolveName($row['ENTITY_TYPE_ID']), $row['ENTITY_ID']];
		}

		return $clients;
	}

	private function getLeadClients($id)
	{
		$clients = [[\CCrmOwnerType::LeadName, $id]];
		$lead = \CCrmLead::GetByID($id, false);
		if($lead)
		{
			$contactId = isset($lead['CONTACT_ID']) ? intval($lead['CONTACT_ID']) : 0;
			$companyId = isset($lead['COMPANY_ID']) ? intval($lead['COMPANY_ID']) : 0;
			if ($contactId > 0)
			{
				$clients[] = [\CCrmOwnerType::ContactName, $contactId];
			}
			if ($companyId > 0)
			{
				$clients[] = [\CCrmOwnerType::CompanyName, $companyId];
			}
		}
		return $clients;
	}

	private function getItemClients($entityTypeId, $entityId): array
	{
		$clients = [];
		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId);
		if (!$factory)
		{
			return $clients;
		}

		$item = $factory->getItem($entityId);
		if (!$item)
		{
			return $clients;
		}

		$companyId =
			$item->hasField(\Bitrix\Crm\Item::FIELD_NAME_COMPANY_ID)
				? $item->getCompanyId()
				: 0
		;
		$contactIds =
			$item->hasField(\Bitrix\Crm\Item::FIELD_NAME_CONTACT_IDS)
				? $item->getContactIds()
				: []
		;

		if ($companyId > 0)
		{
			$clients[] = [\CCrmOwnerType::CompanyName, $companyId];
		}

		foreach ($contactIds as $contactId)
		{
			$clients[] = [\CCrmOwnerType::ContactName, $contactId];
		}

		return $clients;
	}

	private function writeWarning($text)
	{
		$this->WriteToTrackingService($text, 0, CBPTrackingType::Custom);
	}

	private function writeError($errorText, $userId = 0)
	{
		$this->WriteToTrackingService($errorText, 0, CBPTrackingType::Error);

		$timelineText = GetMessage('IMOL_MA_TIMELINE_ERROR', ['#ERROR_TEXT#' => $errorText]);
		$this->writeToTimeline($timelineText, $userId);
	}

	/**
	 * The text of the message is already in the chat when attachments are refused, so the timeline hears
	 * about the files and not about the message.
	 */
	private function writeAttachmentError($errorText, $userId = 0)
	{
		$this->WriteToTrackingService($errorText, 0, CBPTrackingType::Error);

		$timelineText = GetMessage('IMOL_MA_TIMELINE_ATTACHMENT_ERROR', ['#ERROR_TEXT#' => $errorText]);
		$this->writeToTimeline($timelineText, $userId);
	}

	protected function writeToTimeline($text, $userId)
	{
		$timelineController = \Bitrix\Crm\Timeline\BizprocController::getInstance();

		if (method_exists($timelineController, 'onActivityError'))
		{
			$timelineController->onActivityError($this, $userId, $text);
		}
	}
}
