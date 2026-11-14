<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Ui\Text;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * Renames one file of the Drive on behalf of the operator, keeping the extension the file already has.
 *
 * @property-write mixed SourceId
 * @property-write mixed NewName
 * @property-write mixed Operator
 * @property-write ?int ObjectId
 * @property-write ?string Name
 * @property-write ?string DetailUrl
 */
class CBPDiskRenameFileActivity extends BaseActivity implements IBPConfigurableActivity
{
	private const PARAM_SOURCE_ID = 'SourceId';
	private const PARAM_NEW_NAME = 'NewName';
	private const PARAM_OPERATOR = 'Operator';

	private const RETURN_OBJECT_ID = 'ObjectId';
	private const RETURN_NAME = 'Name';
	private const RETURN_DETAIL_URL = 'DetailUrl';

	protected static $requiredModules = ['disk'];

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			self::PARAM_SOURCE_ID => null,
			self::PARAM_NEW_NAME => '',
			self::PARAM_OPERATOR => null,

			// return
			self::RETURN_OBJECT_ID => null,
			self::RETURN_NAME => null,
			self::RETURN_DETAIL_URL => null,
		];

		$this->setPropertiesTypes([
			self::PARAM_SOURCE_ID => ['Type' => FieldType::INT],
			self::PARAM_NEW_NAME => ['Type' => FieldType::STRING],
			self::PARAM_OPERATOR => ['Type' => FieldType::USER],
			self::RETURN_OBJECT_ID => ['Type' => FieldType::INT],
			self::RETURN_NAME => ['Type' => FieldType::STRING],
			self::RETURN_DETAIL_URL => ['Type' => FieldType::STRING],
		]);
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	protected function reInitialize()
	{
		parent::reInitialize();

		$this->setProperty(self::RETURN_OBJECT_ID, null);
		$this->setProperty(self::RETURN_NAME, null);
		$this->setProperty(self::RETURN_DETAIL_URL, null);
	}

	protected function internalExecute(): ErrorCollection
	{
		// An expression of the author yields a value of any shape, so every property is normalized first
		$sourceId = (int)$this->{self::PARAM_SOURCE_ID};
		$newName = trim(\CBPHelper::stringify($this->{self::PARAM_NEW_NAME}));
		$operatorId = (int)\CBPHelper::extractFirstUser($this->{self::PARAM_OPERATOR}, $this->getDocumentId());

		if ($sourceId <= 0)
		{
			return $this->failWith('DISK_RENAME_FILE_ACTIVITY_ERROR_EMPTY_SOURCE');
		}

		if ($newName === '')
		{
			return $this->failWith('DISK_RENAME_FILE_ACTIVITY_ERROR_EMPTY_NAME');
		}

		if ($operatorId <= 0)
		{
			return $this->failWith('DISK_RENAME_FILE_ACTIVITY_ERROR_EMPTY_OPERATOR');
		}

		$object = BaseObject::loadById($sourceId);
		if (!$object)
		{
			return $this->failWith('DISK_RENAME_FILE_ACTIVITY_ERROR_FILE_NOT_FOUND');
		}

		if (!($object instanceof File))
		{
			return $this->failWith('DISK_RENAME_FILE_ACTIVITY_ERROR_NOT_A_FILE');
		}

		if (!$this->canOperatorRename($object, $operatorId))
		{
			return $this->failWith('DISK_RENAME_FILE_ACTIVITY_ERROR_ACCESS_DENIED');
		}

		$name = $this->buildName($newName, $object);
		if ($name === null)
		{
			return $this->failWith('DISK_RENAME_FILE_ACTIVITY_ERROR_EMPTY_NAME');
		}

		// The name the file already carries is a success of its own, and no write is asked for
		if ($name !== $object->getName())
		{
			$errors = $this->renameFile($object, $name);
			if (!$errors->isEmpty())
			{
				return $errors;
			}
		}

		$this->publishResult($object);

		return new ErrorCollection();
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_SOURCE_ID => [
				'Name' => Loc::getMessage('DISK_RENAME_FILE_ACTIVITY_FIELD_SOURCE_ID') ?? '',
				'FieldName' => 'source_id',
				'Type' => FieldType::INT,
				'Required' => true,
				'AllowSelection' => true,
			],
			self::PARAM_NEW_NAME => [
				'Name' => Loc::getMessage('DISK_RENAME_FILE_ACTIVITY_FIELD_NEW_NAME') ?? '',
				'FieldName' => 'new_name',
				'Type' => FieldType::STRING,
				'Required' => true,
				'AllowSelection' => true,
			],
			self::PARAM_OPERATOR => [
				'Name' => Loc::getMessage('DISK_RENAME_FILE_ACTIVITY_FIELD_OPERATOR') ?? '',
				'FieldName' => 'operator',
				'Type' => FieldType::USER,
				'Required' => true,
				'AllowSelection' => true,
			],
		];
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return static::getPropertiesMap([]);
	}

	/** The right is the right of the operator: the node runs outside any request of a user. */
	private function canOperatorRename(File $file, int $operatorId): bool
	{
		$securityContext = $file->getStorage()?->getSecurityContext($operatorId);

		return $securityContext && $file->canRename($securityContext);
	}

	/**
	 * The extension is kept by the server: the process configures the base name alone, and a file without
	 * an extension takes that name whole.
	 *
	 * @return string|null null when the rules of the module leave nothing of the name
	 */
	private function buildName(string $newName, File $file): ?string
	{
		$extension = (string)$file->getExtension();
		$name = Text::correctFilename($extension === '' ? $newName : $newName . '.' . $extension);

		return ($name === '' || $name === '.' . $extension) ? null : $name;
	}

	/**
	 * Renaming belongs to the module: an occupied name is its refusal, which reaches the journal of the
	 * process as it is, and a unique name is never made up in its place.
	 */
	private function renameFile(File $file, string $name): ErrorCollection
	{
		$errors = new ErrorCollection();

		try
		{
			$isRenamed = $file->rename($name);
		}
		catch (\Throwable)
		{
			$isRenamed = false;
		}

		if ($isRenamed)
		{
			return $errors;
		}

		foreach ($file->getErrors() as $error)
		{
			if ((string)$error->getMessage() !== '')
			{
				$errors->setError($error);
			}
		}

		if ($errors->isEmpty())
		{
			$errors->setError(
				new Error(Loc::getMessage('DISK_RENAME_FILE_ACTIVITY_ERROR_RENAME_FAILED') ?? '')
			);
		}

		return $errors;
	}

	private function publishResult(File $file): void
	{
		$urlManager = Driver::getInstance()->getUrlManager();

		$this->setProperty(self::RETURN_OBJECT_ID, (int)$file->getId());
		$this->setProperty(self::RETURN_NAME, $file->getName());
		// The address is absolute, as it is in the neighbouring actions of the Drive: a path alone leads
		// nowhere in the letter or the notification of the next step
		$this->setProperty(
			self::RETURN_DETAIL_URL,
			$urlManager->encodeUrn($urlManager->getHostUrl() . $urlManager->getPathFileDetail($file)),
		);
	}

	private function failWith(string $phraseCode): ErrorCollection
	{
		$errors = new ErrorCollection();
		$errors->setError(new Error(Loc::getMessage($phraseCode) ?? ''));

		return $errors;
	}
}
