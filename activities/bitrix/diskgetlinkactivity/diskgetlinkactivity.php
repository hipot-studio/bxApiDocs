<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Driver;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\Internal\Dto\Audit\AuditActionContext;
use Bitrix\Disk\Internal\Entity\Audit\AuditContext;
use Bitrix\Disk\Internal\Service\Audit\AuditActionContextResolver;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContext;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContextStack;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Public\Provider\ExternalLinkProvider;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\UrlManager;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

/**
 * @property-write string|int|null ObjectId
 * @property-write mixed UserId
 * @property-write string|null Type
 * @property-write mixed PublicLinkExpireDate
 * @property-write string|null PublicLinkPassword
 * @property-write string|null PublicLinkAccessRight
 * @property-write string|bool|null ShortenPublicLink
 * @property-write string|null Link
 * @property-write \Bitrix\Main\Type\DateTime|null PublicLinkExpiresAt
 */
class CBPDiskGetLinkActivity extends BaseActivity implements IBPConfigurableActivity
{
	private const PARAM_OBJECT_ID = 'ObjectId';
	private const PARAM_USER_ID = 'UserId';
	private const PARAM_TYPE = 'Type';
	private const PARAM_PUBLIC_LINK_EXPIRE_DATE = 'PublicLinkExpireDate';
	private const PARAM_PUBLIC_LINK_PASSWORD = 'PublicLinkPassword';
	private const PARAM_PUBLIC_LINK_ACCESS_RIGHT = 'PublicLinkAccessRight';
	private const PARAM_SHORTEN_PUBLIC_LINK = 'ShortenPublicLink';

	private const RETURN_LINK = 'Link';
	private const RETURN_PUBLIC_LINK_EXPIRES_AT = 'PublicLinkExpiresAt';
	private const RETURN_PROPERTIES = 'Return';

	private const TYPE_INTERNAL = 'internal';
	private const TYPE_DOWNLOAD = 'download';
	private const TYPE_PUBLIC = 'public';

	private const ACCESS_RIGHT_VIEW = 'view';
	private const ACCESS_RIGHT_EDIT = 'edit';

	protected static $requiredModules = ['disk'];

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			self::PARAM_OBJECT_ID => null,
			self::PARAM_USER_ID => null,
			self::PARAM_TYPE => self::TYPE_INTERNAL,
			self::PARAM_PUBLIC_LINK_EXPIRE_DATE => null,
			self::PARAM_PUBLIC_LINK_PASSWORD => '',
			self::PARAM_PUBLIC_LINK_ACCESS_RIGHT => self::ACCESS_RIGHT_VIEW,
			self::PARAM_SHORTEN_PUBLIC_LINK => 'N',

			// return
			self::RETURN_LINK => null,
			self::RETURN_PUBLIC_LINK_EXPIRES_AT => null,
		];

		$this->setPropertiesTypes([
			'Title' => ['Type' => FieldType::STRING],
			self::PARAM_OBJECT_ID => ['Type' => FieldType::INT],
			self::PARAM_USER_ID => ['Type' => FieldType::USER],
			self::PARAM_TYPE => ['Type' => FieldType::SELECT],
			self::PARAM_PUBLIC_LINK_EXPIRE_DATE => ['Type' => FieldType::DATETIME],
			self::PARAM_PUBLIC_LINK_PASSWORD => ['Type' => FieldType::STRING],
			self::PARAM_PUBLIC_LINK_ACCESS_RIGHT => ['Type' => FieldType::SELECT],
			self::PARAM_SHORTEN_PUBLIC_LINK => ['Type' => FieldType::BOOL],
			self::RETURN_LINK => ['Type' => FieldType::STRING],
			self::RETURN_PUBLIC_LINK_EXPIRES_AT => ['Type' => FieldType::DATETIME],
		]);
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	protected function reInitialize()
	{
		parent::reInitialize();

		$this->setProperty(self::RETURN_LINK, null);
		$this->setProperty(self::RETURN_PUBLIC_LINK_EXPIRES_AT, null);
	}

	protected function internalExecute(): ErrorCollection
	{
		$errors = new ErrorCollection();

		// One block of normalisation: below it the properties are not read again.
		$objectId = self::toPositiveIntOrNull($this->{self::PARAM_OBJECT_ID});
		$userId = $this->resolveUserId($this->{self::PARAM_USER_ID});
		$type = self::pickOption($this->{self::PARAM_TYPE}, self::getTypeOptions());
		$accessRight = self::pickOption($this->{self::PARAM_PUBLIC_LINK_ACCESS_RIGHT}, self::getAccessRightOptions());
		$rawExpiresAt = $this->{self::PARAM_PUBLIC_LINK_EXPIRE_DATE};
		$expiresAt = self::toDateTimeOrNull($rawExpiresAt);
		$password = trim(\CBPHelper::stringify($this->{self::PARAM_PUBLIC_LINK_PASSWORD}));
		$isShortened = \CBPHelper::getBool($this->{self::PARAM_SHORTEN_PUBLIC_LINK});

		if ($objectId === null)
		{
			$errors->setError(self::error('BPDGL_ERROR_EMPTY_OBJECT'));

			return $errors;
		}

		if ($userId <= 0)
		{
			$errors->setError(self::error('BPDGL_ERROR_EMPTY_USER'));

			return $errors;
		}

		if ($type === null)
		{
			$errors->setError(self::error('BPDGL_ERROR_UNKNOWN_TYPE'));

			return $errors;
		}

		// The settings of a public link are read by the public branch alone: the form keeps them saved
		// after the type has been switched, and every other type ignores them.
		if ($type === self::TYPE_PUBLIC)
		{
			$publicSettingsError = self::checkPublicSettings($accessRight, $rawExpiresAt, $expiresAt);
			if ($publicSettingsError !== null)
			{
				$errors->setError($publicSettingsError);

				return $errors;
			}
		}

		$link = null;
		$publicLinkExpiresAt = null;

		try
		{
			$object = BaseObject::loadById($objectId, ['STORAGE']);
			$storage = $object?->getStorage();
			if (!($object instanceof File || $object instanceof Folder) || $storage === null)
			{
				$errors->setError(self::error('BPDGL_ERROR_OBJECT_NOT_FOUND'));
			}
			elseif ($object->isDeleted())
			{
				// An object in the recycle bin is not published by the module at all, and an address of one
				// does not open.
				$errors->setError(self::error('BPDGL_ERROR_OBJECT_IN_TRASHCAN'));
			}
			else
			{
				$securityContext = $storage->getSecurityContext($userId);

				if ($type === self::TYPE_PUBLIC)
				{
					$externalLink = $this->resolvePublicLink(
						$object,
						$securityContext,
						$userId,
						(string)$accessRight,
						$expiresAt,
						$password,
						$errors,
					);

					if ($externalLink !== null)
					{
						$link = $this->composePublicLink($externalLink, $isShortened);
						$publicLinkExpiresAt = $externalLink->getDeathTime();
					}
				}
				else
				{
					$link = $this->composePrivateLink($object, $securityContext, $type, $errors);
				}
			}
		}
		catch (\Throwable)
		{
			// Nothing may leave this method: an exception out of a node stops the whole process, and an
			// Error out of it brings down the request.
			$errors->setError(self::error('BPDGL_ERROR_LINK_NOT_CREATED'));
		}

		if (!$errors->isEmpty())
		{
			return $errors;
		}

		if ($link === null || $link === '')
		{
			$errors->setError(self::error('BPDGL_ERROR_LINK_NOT_CREATED'));

			return $errors;
		}

		$this->setProperty(self::RETURN_LINK, $link);
		$this->setProperty(self::RETURN_PUBLIC_LINK_EXPIRES_AT, $publicLinkExpiresAt);

		return $errors;
	}

	/** The address of the object inside the portal, and for a file also the address of its content. */
	private function composePrivateLink(
		File|Folder $object,
		SecurityContext $securityContext,
		string $type,
		ErrorCollection $errors,
	): ?string
	{
		$urlManager = Driver::getInstance()->getUrlManager();

		if ($type === self::TYPE_DOWNLOAD)
		{
			if (!$object instanceof File)
			{
				$errors->setError(self::error('BPDGL_ERROR_DOWNLOAD_ONLY_FILE'));

				return null;
			}

			if (!$object->canDownload($securityContext))
			{
				$errors->setError(self::error('BPDGL_ERROR_ACCESS_DENIED'));

				return null;
			}

			return $urlManager->getUrlForDownloadFile($object, true);
		}

		if (!$object->canRead($securityContext))
		{
			$errors->setError(self::error('BPDGL_ERROR_ACCESS_DENIED'));

			return null;
		}

		$path = $object instanceof File
			? $urlManager->getPathFileDetail($object)
			: $urlManager->getPathFolderList($object)
		;

		return $urlManager->encodeUrn($urlManager->getHostUrl() . $path);
	}

	/**
	 * The public link of the object, settled to the settings of the node: the live manual link of the
	 * object when it has one, a new one when it has none.
	 *
	 * A public link belongs to the object and not to a run of the node. The address of an object is served
	 * by its newest live manual link (@see \Bitrix\Disk\Public\Provider\ExternalLinkProvider), so a second
	 * link would silently take over the address already handed out, with settings of its own. This is the
	 * path of the module itself, @see \Bitrix\Disk\Controller\BaseObject::generateExternalLink().
	 */
	private function resolvePublicLink(
		File|Folder $object,
		SecurityContext $securityContext,
		int $userId,
		string $accessRight,
		?DateTime $expiresAt,
		string $password,
		ErrorCollection $errors,
	): ?ExternalLink
	{
		$realObject = $object->getRealObject();
		if ($realObject === null)
		{
			$errors->setError(self::error('BPDGL_ERROR_ACCESS_DENIED'));

			return null;
		}

		// Publishing is refused in the order of the module: what the portal allows first, what the employee
		// may after, @see \Bitrix\Disk\Controller\AccessRights::checkManageExternalLink().
		if (!self::isPublicLinkAllowed($realObject))
		{
			$errors->setError(self::error('BPDGL_ERROR_PUBLIC_LINK_DISABLED'));

			return null;
		}

		if (!$realObject->canManageExternalLink($securityContext))
		{
			$errors->setError(self::error('BPDGL_ERROR_ACCESS_DENIED'));

			return null;
		}

		// A selected edit right must not stop the workflow for a file the public link cannot edit. The link
		// remains useful with the viewing right, which is the access the module itself offers in this case.
		if ($accessRight === self::ACCESS_RIGHT_EDIT)
		{
			if (!$realObject->canUpdate($securityContext) || !self::isEditableDocument($realObject))
			{
				$accessRight = self::ACCESS_RIGHT_VIEW;
			}
		}

		// Revoked and expired links are not offered here: a revoked one is deleted and a live one is what
		// the provider looks for, so neither is ever handed out as the working address.
		$externalLink = ServiceLocator::getInstance()
			->get(ExternalLinkProvider::class)
			->getForUse($realObject->getId())
		;

		return $this->settlePublicLink(
			$realObject,
			$externalLink,
			$userId,
			$accessRight,
			$expiresAt,
			$password,
			$errors,
		);
	}

	/**
	 * Creating the link and applying the settings to it go in one transaction, the way the access popup
	 * of the module runs them, @see \Bitrix\Disk\Controller\AccessRights::runPublicLinkRightsCommand().
	 * A refusal must not leave a live public link behind: an object inside the tree of the mail
	 * attachments, for one, is given a link whose settings are locked
	 * (@see \Bitrix\Disk\BaseObject::addExternalLink()), and the very next step refuses.
	 *
	 * The mutators of the link join the transaction of their own accord, @see AuditTransaction::run().
	 *
	 * The whole settlement runs under the action context of the employee the node acts for: creating the
	 * link names him itself ('CREATED_BY'), while every mutator takes the author of its audit event from
	 * the global user (@see \Bitrix\Disk\ExternalLink::getCurrentUserId()), which in a run of a process is
	 * a stranger or nobody at all. The context of the operation wins over that user
	 * (@see \Bitrix\Disk\Internal\Service\Audit\AuditPublicLinkEventProducer::sendLinkEvent()), so the
	 * journal of the module records the run on one and the same employee from end to end.
	 */
	private function settlePublicLink(
		BaseObject $realObject,
		?ExternalLink $externalLink,
		int $userId,
		string $accessRight,
		?DateTime $expiresAt,
		string $password,
		ErrorCollection $errors,
	): ?ExternalLink
	{
		$settle = function (AuditTransaction $transaction) use (
			$realObject,
			$externalLink,
			$userId,
			$accessRight,
			$expiresAt,
			$password,
			$errors,
		): ?ExternalLink {
			$externalLink ??= $this->createPublicLink(
				$realObject,
				$userId,
				$accessRight,
				$expiresAt,
				$password,
				$transaction,
				$errors,
			);

			return $externalLink === null
				? null
				: $this->applyPublicSettings($externalLink, $accessRight, $expiresAt, $password, $errors)
			;
		};

		$settled = AuditOperationContextStack::runWithContextIfEmpty(
			AuditOperationContext::create(actionContext: $this->createAuditActionContext($userId)),
			static fn(): mixed => AuditTransaction::run($settle),
		);

		return $settled instanceof ExternalLink ? $settled : null;
	}

	/**
	 * The initiator of the operation is the employee of the node. The channel is the one the module builds
	 * for its own access popup (@see \Bitrix\Disk\Internals\Engine\Controller::getActionResponse()): the
	 * journal has no channel of automation, and the system one would replace the employee with the system
	 * user. That the run came from a process is carried by the type of the context.
	 */
	private function createAuditActionContext(int $userId): AuditActionContext
	{
		return (new AuditActionContextResolver())->resolveForWeb(
			userId: $userId,
			externalContext: [
				'type' => AuditContext::TYPE_BIZPROC,
				'entityType' => 'workflow',
				'entityId' => (string)$this->getWorkflowInstanceId(),
			],
		);
	}

	private function createPublicLink(
		BaseObject $realObject,
		int $userId,
		string $accessRight,
		?DateTime $expiresAt,
		string $password,
		AuditTransaction $transaction,
		ErrorCollection $errors,
	): ?ExternalLink
	{
		$data = [
			'CREATED_BY' => $userId,
			'TYPE' => ExternalLink::TYPE_MANUAL,
			'ACCESS_RIGHT' => self::toExternalLinkAccessRight($accessRight),
		];

		if ($expiresAt !== null)
		{
			$data['DEATH_TIME'] = $expiresAt;
		}

		if ($password !== '')
		{
			$data['PASSWORD'] = $password;
		}

		$externalLink = $realObject->addExternalLink($data, $transaction);
		if (!$externalLink instanceof ExternalLink)
		{
			$errors->setError(self::error('BPDGL_ERROR_PUBLIC_LINK_NOT_CREATED'));

			return null;
		}

		return $externalLink;
	}

	/**
	 * The settings of the node become the settings of the link, the same way the access popup of the
	 * module applies them, @see \Bitrix\Disk\Controller\AccessRights::applyPublicLinkRights(). A setting
	 * left empty is not left alone but taken off the link: what the process hands out has to be the
	 * address the node was configured to hand out.
	 *
	 * The password and the expiry are applied before the access right, so that no step of a run widens
	 * the link earlier than it puts the protection of it in place. Every mutator joins the transaction of
	 * the run and publishes its audit event; a setting that already matches is not written at all.
	 */
	private function applyPublicSettings(
		ExternalLink $externalLink,
		string $accessRight,
		?DateTime $expiresAt,
		string $password,
		ErrorCollection $errors,
	): ?ExternalLink
	{
		$applied = self::applyPassword($externalLink, $password)
			&& self::applyExpiry($externalLink, $expiresAt)
			&& $externalLink->changeAccessSettings(
				self::toExternalLinkAccessRight($accessRight),
				$externalLink->isCanDownloadWithReadAccess(),
			)
		;

		if (!$applied)
		{
			$errors->setError(self::error('BPDGL_ERROR_PUBLIC_LINK_NOT_UPDATED'));

			return null;
		}

		return $externalLink;
	}

	private static function applyPassword(ExternalLink $externalLink, string $password): bool
	{
		if ($password === '')
		{
			return !$externalLink->hasPassword() || $externalLink->revokePassword();
		}

		return $externalLink->checkPassword($password) || $externalLink->changePassword($password);
	}

	private static function applyExpiry(ExternalLink $externalLink, ?DateTime $expiresAt): bool
	{
		if ($expiresAt === null)
		{
			return !$externalLink->hasDeathTime() || $externalLink->revokeDeathTime();
		}

		return $externalLink->getDeathTime()?->getTimestamp() === $expiresAt->getTimestamp()
			|| $externalLink->changeDeathTime($expiresAt)
		;
	}

	/**
	 * Publishing is allowed by the portal and by the plan, checked exactly as the access popup checks it,
	 * @see \Bitrix\Disk\Controller\AccessRights::checkManageExternalLink().
	 */
	private static function isPublicLinkAllowed(BaseObject $realObject): bool
	{
		if ($realObject instanceof Folder)
		{
			$feature = 'disk_manual_external_folder';
		}
		elseif ($realObject instanceof File)
		{
			$feature = (int)$realObject->getTypeFile() === TypeFile::BOARD
				? 'disk_board_external_link'
				: 'disk_manual_external_link'
			;
		}
		else
		{
			$feature = null;
		}

		if ($feature !== null && !Bitrix24Manager::isFeatureEnabled($feature))
		{
			return false;
		}

		return Configuration::isPossibleToShowExternalLinkControl();
	}

	/**
	 * An object the module itself edits: a public link carrying the edit right is handed nothing else,
	 * @see \Bitrix\Disk\Controller\AccessRights::isObjectEditable().
	 */
	private static function isEditableDocument(BaseObject $realObject): bool
	{
		return $realObject instanceof File && DocumentHandler::isEditable($realObject->getExtension());
	}

	private static function toExternalLinkAccessRight(string $accessRight): int
	{
		return $accessRight === self::ACCESS_RIGHT_EDIT
			? ExternalLink::ACCESS_RIGHT_EDIT
			: ExternalLink::ACCESS_RIGHT_VIEW
		;
	}

	private function composePublicLink(ExternalLink $externalLink, bool $isShortened): string
	{
		$url = Driver::getInstance()->getUrlManager()->getCopyablePublicExternalLinkForConsumer(
			$externalLink,
			UrlManager::PUBLIC_LINK_CONSUMER_BIZPROC,
		);
		// The short public address is short already and is never put behind a second shortener.
		if (!$isShortened || self::isShortPublicAddress($url, $externalLink))
		{
			return $url;
		}

		// For an object without a unified link the address of the link is a short one already
		// (@see \Bitrix\Disk\UrlManager::getPublicExternalLink()), and shortening it again would only put
		// one more hop in front of it.
		if (\CBXShortUri::getUri($url) !== null)
		{
			return $url;
		}

		// Shortened is the very address the link hands out: the legacy address by hash, the one UrlManager
		// shortens, resolves only on a portal that still carries the rewrite rule of disk.external.link.
		$shortUri = \CBXShortUri::getShortUri($url);

		// A portal that could not register the short address still gets a working one.
		return $shortUri === '' ? $url : Driver::getInstance()->getUrlManager()->getHostUrl() . $shortUri;
	}

	private static function isShortPublicAddress(string $url, ExternalLink $externalLink): bool
	{
		$code = $externalLink->getShortCode();

		return $code !== null && str_ends_with((string)parse_url($url, PHP_URL_PATH), "/docs/pub/s/{$code}/");
	}

	private static function checkPublicSettings(?string $accessRight, mixed $rawExpiresAt, ?DateTime $expiresAt): ?Error
	{
		if ($accessRight === null)
		{
			return self::error('BPDGL_ERROR_UNKNOWN_ACCESS_RIGHT');
		}

		if ($expiresAt === null)
		{
			return \CBPHelper::isEmptyValue($rawExpiresAt) ? null : self::error('BPDGL_ERROR_INVALID_EXPIRE_DATE');
		}

		return $expiresAt->getTimestamp() <= time() ? self::error('BPDGL_ERROR_EXPIRE_DATE_IN_PAST') : null;
	}

	private function resolveUserId(mixed $value): int
	{
		try
		{
			return (int)\CBPHelper::extractFirstUser($value, $this->getDocumentId());
		}
		catch (\Throwable)
		{
			return 0;
		}
	}

	private static function toPositiveIntOrNull(mixed $value): ?int
	{
		// A plain cast is not enough: (int) turns a word, an empty array and null into zero, and the node
		// would go on working with zero instead of saying the value is not set.
		return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
	}

	private static function toDateTimeOrNull(mixed $value): ?DateTime
	{
		if (\CBPHelper::isEmptyValue($value))
		{
			return null;
		}

		$timestamp = \CBPHelper::makeTimestamp($value);

		return $timestamp > 0 ? DateTime::createFromTimestamp($timestamp) : null;
	}

	/**
	 * The value of a select is checked against its list only while the settings form is open: an
	 * expression is let through untouched there and nobody looks at it once it has been expanded.
	 */
	private static function pickOption(mixed $value, array $options): ?string
	{
		$value = is_scalar($value) ? (string)$value : '';

		return array_key_exists($value, $options) ? $value : null;
	}

	private static function error(string $phraseId): Error
	{
		return new Error(Loc::getMessage($phraseId) ?? '');
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_OBJECT_ID => [
				'Name' => Loc::getMessage('BPDGL_FIELD_OBJECT_ID') ?? '',
				'FieldName' => 'object_id',
				'Type' => FieldType::INT,
				'Required' => true,
				'AllowSelection' => true,
			],
			self::PARAM_USER_ID => [
				'Name' => Loc::getMessage('BPDGL_FIELD_USER_ID') ?? '',
				'FieldName' => 'user_id',
				'Type' => FieldType::USER,
				'Required' => true,
			],
			self::PARAM_TYPE => [
				'Name' => Loc::getMessage('BPDGL_FIELD_TYPE') ?? '',
				'FieldName' => 'type',
				'Type' => FieldType::SELECT,
				'Required' => true,
				'Default' => self::TYPE_INTERNAL,
				'Options' => self::getTypeOptions(),
			],
			self::PARAM_PUBLIC_LINK_EXPIRE_DATE => [
				'Name' => Loc::getMessage('BPDGL_FIELD_PUBLIC_LINK_EXPIRE_DATE') ?? '',
				'FieldName' => 'public_link_expire_date',
				'Type' => FieldType::DATETIME,
				'Required' => false,
				'AllowSelection' => true,
				'Options' => [
					'placeholder' => Loc::getMessage('BPDGL_FIELD_PUBLIC_LINK_EXPIRE_DATE_HINT') ?? '',
				],
			],
			self::PARAM_PUBLIC_LINK_PASSWORD => [
				'Name' => Loc::getMessage('BPDGL_FIELD_PUBLIC_LINK_PASSWORD') ?? '',
				'FieldName' => 'public_link_password',
				'Type' => FieldType::STRING,
				'Required' => false,
				'Options' => [
					'placeholder' => Loc::getMessage('BPDGL_FIELD_PUBLIC_LINK_PASSWORD_HINT') ?? '',
				],
			],
			self::PARAM_PUBLIC_LINK_ACCESS_RIGHT => [
				'Name' => Loc::getMessage('BPDGL_FIELD_PUBLIC_LINK_ACCESS_RIGHT') ?? '',
				'FieldName' => 'public_link_access_right',
				'Type' => FieldType::SELECT,
				'Required' => false,
				'Default' => self::ACCESS_RIGHT_VIEW,
				'Options' => self::getAccessRightOptions(),
			],
			self::PARAM_SHORTEN_PUBLIC_LINK => [
				'Name' => Loc::getMessage('BPDGL_FIELD_SHORTEN_PUBLIC_LINK') ?? '',
				'FieldName' => 'shorten_public_link',
				'Type' => FieldType::BOOL,
				'Required' => false,
				'Default' => 'N',
			],
		];
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return static::getPropertiesMap([]);
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors
	): bool
	{
		if (!parent::getPropertiesDialogValues(
			$documentType,
			$activityName,
			$workflowTemplate,
			$workflowParameters,
			$workflowVariables,
			$currentValues,
			$errors,
		))
		{
			return false;
		}

		$currentActivity = &\CBPWorkflowTemplateLoader::FindActivityByName($workflowTemplate, $activityName);
		$type = $currentValues['type']
			?? $currentValues[self::PARAM_TYPE]
			?? $currentActivity['Properties'][self::PARAM_TYPE]
			?? null;
		if ($type === self::TYPE_PUBLIC)
		{
			$currentActivity['Properties'][self::RETURN_PROPERTIES] = [
				self::RETURN_PUBLIC_LINK_EXPIRES_AT => [
					'Name' => Loc::getMessage('BPDGL_DESCR_RETURN_PUBLIC_LINK_EXPIRES_AT') ?? '',
					'Type' => FieldType::DATETIME,
				],
			];
		}
		else
		{
			$currentActivity['Properties'][self::RETURN_PROPERTIES] = [];
		}

		return true;
	}

	/**
	 * The one list of link types: it feeds the settings form and, at run time, tells a type the node can
	 * build from a value an expression has produced.
	 */
	private static function getTypeOptions(): array
	{
		return [
			self::TYPE_INTERNAL => Loc::getMessage('BPDGL_TYPE_INTERNAL') ?? '',
			self::TYPE_DOWNLOAD => Loc::getMessage('BPDGL_TYPE_DOWNLOAD') ?? '',
			self::TYPE_PUBLIC => Loc::getMessage('BPDGL_TYPE_PUBLIC') ?? '',
		];
	}

	private static function getAccessRightOptions(): array
	{
		return [
			self::ACCESS_RIGHT_VIEW => Loc::getMessage('BPDGL_ACCESS_RIGHT_VIEW') ?? '',
			self::ACCESS_RIGHT_EDIT => Loc::getMessage('BPDGL_ACCESS_RIGHT_EDIT') ?? '',
		];
	}
}
