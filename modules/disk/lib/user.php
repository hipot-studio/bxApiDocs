<?php

namespace Bitrix\Disk;

use Bitrix\Disk\Document\Models\GuestUser;
use Bitrix\Disk\Integration\Collab\CollabService;
use Bitrix\Main\Authentication\Context;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Entity\Result;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\UserTable;
use CUser;

class User extends Internals\Model
{
	/** Attributes a model may be built without, {@see User::loadDeferredAttribute()}. */
	private const DEFERRED_ATTRIBUTES = [
		'ACTIVE' => 'active',
		'WORK_POSITION' => 'workPosition',
		'LID' => 'lid',
		'EXTERNAL_AUTH_ID' => 'externalAuthId',
	];

	/** @var int */
	protected $id;
	/** @var string */
	protected $login;
	/** @var string */
	protected $formattedName;
	/** @var string */
	protected $password;
	/** @var string */
	protected $email;
	/** @var string */
	protected $active;
	/** @var string */
	protected $name;
	/** @var string */
	protected $secondName;
	/** @var string */
	protected $lastName;
	/** @var string */
	protected $workPosition;
	protected $lid;
	/** @var string */
	protected $personalGender;
	/** @var int */
	protected $personalPhoto;
	/** @var string */
	protected $isOnline;
	/** @var string */
	protected $externalAuthId;
	/** @var bool */
	private $isIntranetUser;
	/** @var bool */
	private $isExternalAuthEmail;
	/** @var bool */
	private $isCrmEmail;
	private $isExternalBotUser = false;

	/** @var User[] */
	protected static $loadedUsers;

	/**
	 * Gets the fully qualified name of table class which belongs to current model.
	 * @throws \Bitrix\Main\NotImplementedException
	 * @return string
	 */
	public static function getTableClassName()
	{
		return 'Bitrix\Main\UserTable';
	}

	/**
	 * Builds the authorized user from the session instead of reading b_user, {@see User::getAttributesFromSession()}.
	 * Such a model is narrower than the one of {@see Internals\Model::loadBatchById()}, which selects every column.
	 * It also outlives a later reference select of the same user: {@see User::buildFromArray()} keeps the cached
	 * model and drops the row, so ACTIVE, WORK_POSITION and LID cost one narrow query where they used to be free.
	 * @inheritdoc
	 */
	public static function loadById($id, array $with = array())
	{
		if(isset(self::$loadedUsers[$id]))
		{
			return self::$loadedUsers[$id];
		}

		if(SystemUser::isSystemUserId($id))
		{
			self::$loadedUsers[$id] = SystemUser::create();
		}
		elseif(GuestUser::isGuestUserId($id))
		{
			self::$loadedUsers[$id] = GuestUser::create();
		}
		elseif(($sessionAttributes = self::getAttributesFromSession($id)) !== null)
		{
			self::$loadedUsers[$id] = static::buildFromArray($sessionAttributes);
		}
		else
		{
			self::$loadedUsers[$id] = parent::loadById($id, $with);
		}

		return self::$loadedUsers[$id];
	}

	/**
	 * Returns attributes of the authorized user which the session already keeps, so the model is built without reading b_user.
	 * ACTIVE, WORK_POSITION and LID are absent there; EXTERNAL_AUTH_ID is skipped as the kernel leaves a stale value
	 * in a live session, {@see \CUser::Update()}. All of them are read on demand, {@see User::loadDeferredAttribute()}.
	 * @param int $id Id of user.
	 * @return array|null Null when there is nothing to build the model from: no session, no authorization, another user.
	 */
	private static function getAttributesFromSession($id): ?array
	{
		global $USER;

		if (!($USER instanceof CUser))
		{
			return null;
		}

		// the id comes from the same snapshot as the data, unlike $USER->getId(), {@see User::getSessionUserId()}
		$sessionUserId = self::getSessionUserId($USER);
		if ($sessionUserId <= 0 || (int)$id !== $sessionUserId)
		{
			return null;
		}

		$login = $USER->getParam('LOGIN');
		if (empty($login))
		{
			return null;
		}

		return [
			'ID' => (int)$id,
			'LOGIN' => $login,
			'EMAIL' => $USER->getParam('EMAIL'),
			// in terms of b_user the column NAME is kept in the session as FIRST_NAME, while its NAME is the full name
			'NAME' => $USER->getParam('FIRST_NAME'),
			'SECOND_NAME' => $USER->getParam('SECOND_NAME'),
			'LAST_NAME' => $USER->getParam('LAST_NAME'),
			'PERSONAL_GENDER' => $USER->getParam('PERSONAL_GENDER'),
			'PERSONAL_PHOTO' => $USER->getParam('PERSONAL_PHOTO'),
		];
	}

	/**
	 * Returns id of the user the session is authorized as, taken from the same snapshot as the rest of its data.
	 * The kernel keeps no USER_ID in the session, {@see \CUser::UpdateSessionData()}, so \CUser::getId() answers from
	 * the context cached in the object, which a re-authorization inside a hit leaves behind, {@see \CUser::getContext()}.
	 * @param CUser $user Kernel user.
	 * @return int Zero when the session is authorized as nobody.
	 */
	private static function getSessionUserId(CUser $user): int
	{
		return (int)Context::jsonDecode((string)$user->getParam('CONTEXT'))->getUserId();
	}

	/**
	 * Builds model from array.
	 * @param array $attributes Model attributes.
	 * @param array &$aliases Aliases.
	 * @internal
	 * @return static
	 */
	public static function buildFromArray(array $attributes, array &$aliases = null)
	{
		$id = $attributes['ID'];
		if(isset(self::$loadedUsers[$id]))
		{
			return self::$loadedUsers[$id];
		}

		if(SystemUser::isSystemUserId($id))
		{
			self::$loadedUsers[$id] = SystemUser::create();
		}
		elseif(GuestUser::isGuestUserId($id))
		{
			self::$loadedUsers[$id] = GuestUser::create();
		}
		else
		{
			$userModel = parent::buildFromArray($attributes, $aliases);

			if (isset($attributes['UF_DEPARTMENT']))
			{
				$userModel->resolveFlagsFromRow($attributes);
			}

			/** @see \Bitrix\Im\Bot::EXTERNAL_AUTH_ID */
			if (isset($attributes['EXTERNAL_AUTH_ID']) &&  $attributes['EXTERNAL_AUTH_ID'] === 'bot')
			{
				$userModel->setIsBotUser(true);
			}

			self::$loadedUsers[$id] = $userModel;
		}

		return self::$loadedUsers[$id];
	}

	/**
	 * Builds model from \Bitrix\Main\Entity\Result.
	 * @param Result $result Query result.
	 * @return static
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public static function buildFromResult(Result $result)
	{
		/** @var Storage $model */
		$model = parent::buildFromResult($result);
		self::$loadedUsers[$model->getId()] = $model;

		return $model;
	}

	/**
	 * @return string
	 */
	public function getActive()
	{
		$this->loadDeferredAttribute('active');

		return $this->active;
	}

	/**
	 * @return string
	 */
	public function getEmail()
	{
		return $this->email;
	}

	/**
	 * Returns type of external authorization.
	 * Neither {@see User::getFieldsForSelect()} nor the session of the current user keeps a usable value of the field,
	 * so for a model built from either of them it is read on demand, {@see User::loadDeferredAttribute()}.
	 * @return string|null
	 */
	public function getExternalAuthId(): ?string
	{
		$this->loadDeferredAttribute('externalAuthId');

		return $this->externalAuthId;
	}

	/**
	 * @return int
	 */
	public function getId()
	{
		return $this->id;
	}

	/**
	 * @return string
	 */
	public function getLastName()
	{
		return $this->lastName;
	}

	/**
	 * @return mixed
	 */
	public function getLid()
	{
		$this->loadDeferredAttribute('lid');

		return $this->lid;
	}

	/**
	 * @return string
	 */
	public function getLogin()
	{
		return $this->login;
	}

	/**
	 * @return string
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * @return string
	 */
	public function getPersonalGender()
	{
		return $this->personalGender == 'F'? 'F' : 'M';
	}

	public function getPersonalGenderExact()
	{
		return $this->personalGender;
	}

	/**
	 * @return int
	 */
	public function getPersonalPhoto()
	{
		return $this->personalPhoto;
	}

	/**
	 * @return string
	 */
	public function getSecondName()
	{
		return $this->secondName;
	}

	/**
	 * @return string
	 */
	public function getWorkPosition()
	{
		$this->loadDeferredAttribute('workPosition');

		return $this->workPosition;
	}

	/**
	 * Reads an attribute the model was built without, so it is not silently reported as null.
	 * The session of the current user keeps none of ACTIVE, WORK_POSITION, LID and a fresh EXTERNAL_AUTH_ID,
	 * {@see User::getFieldsForSelect()} keeps neither LID nor EXTERNAL_AUTH_ID, so every absent one of them
	 * is read at once: one query instead of four.
	 * @param string $attribute Requested attribute.
	 */
	private function loadDeferredAttribute(string $attribute): void
	{
		if ($this->isLoadedAttribute($attribute) || (int)$this->getId() <= 0)
		{
			return;
		}

		$absent = array_filter(self::DEFERRED_ATTRIBUTES, fn(string $name): bool => !$this->isLoadedAttribute($name));
		if ($absent === [])
		{
			return;
		}

		$row = UserTable::getRow([
			'select' => array_keys($absent),
			'filter' => ['=ID' => (int)$this->getId()],
		]);

		foreach ($absent as $field => $name)
		{
			$this->{$name} = $row[$field] ?? null;
			$this->setAsLoadedAttribute($name);
		}
	}

	/**
	 * @return array
	 */
	public static function getMapAttributes()
	{
		return array(
			'ID' => 'id',
			'LOGIN' => 'login',
			'EMAIL' => 'email',
			'ACTIVE' => 'active',
			'NAME' => 'name',
			'SECOND_NAME' => 'secondName',
			'LAST_NAME' => 'lastName',
			'WORK_POSITION' => 'workPosition',
			'LID' => 'lid',
			'PERSONAL_GENDER' => 'personalGender',
			'PERSONAL_PHOTO' => 'personalPhoto',
			'IS_ONLINE' => 'isOnline',
			'EXTERNAL_AUTH_ID' => 'externalAuthId',
		);
	}

	/**
	 * Getting fields for sql select. Use in reference map for optimization
	 * @return array
	 * @internal
	 */
	public static function getFieldsForSelect()
	{
		return array(
			'ID',
			'LOGIN',
			'EMAIL',
			'NAME',
			'SECOND_NAME',
			'LAST_NAME',
			'PERSONAL_GENDER',
			'PERSONAL_PHOTO',
			'ACTIVE',
			'WORK_POSITION'
		);
	}

	/**
	 * The model maps a small part of the columns of b_user, so loading reads only them.
	 * IS_ONLINE is calculated, not a column, and is left out as it is with a select of all columns.
	 * @inheritdoc
	 */
	protected static function getSelectForLoad(): array
	{
		return array_values(array_diff(array_keys(static::getMapAttributes()), ['IS_ONLINE']));
	}

	public function isEmptyName()
	{
		return empty($this->name) && empty($this->lastName) && empty($this->secondName);
	}

	public function getFormattedName()
	{
		if($this->formattedName === null)
		{
			$this->formattedName = \CUser::formatName(\CSite::GetNameFormat(), array(
				'NAME' => $this->name,
				'LAST_NAME' => $this->lastName,
				'SECOND_NAME' => $this->secondName,
				'EMAIL' => $this->email,
				'ID' => $this->id,
				'LOGIN' => $this->login,
			), true, false);
		}
		return $this->formattedName;
	}

	public function isIntranetUser()
	{
		if($this->isIntranetUser !== null)
		{
			return $this->isIntranetUser;
		}

		$this->isIntranetUser = false;
		$this->isExternalAuthEmail = false;
		$this->isCrmEmail = false;
		if(!Loader::includeModule('intranet'))
		{
			return false;
		}

		$user = $this->readIntranetAttributes();
		if ($user !== false)
		{
			$this->resolveFlagsFromRow($user);
		}

		return $this->isIntranetUser;
	}

	/**
	 * Warms the intranet flag of a list of users with one query instead of one per user: the check of an opening
	 * by a unified link asks it of every candidate, {@see \Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessCheckHandler},
	 * and a model that has not been asked before answers with a query of its own. Both paths read the same
	 * attributes and build their verdict with the same code, {@see User::resolveFlagsFromRow()}, so a warmed
	 * model answers exactly as it would have answered alone. The current user is left out: for him the single
	 * path reads a cache and costs no query.
	 *
	 * @param array<array-key, int> $userIds Users already built as models, {@see User::loadBatchById()}.
	 */
	public static function preloadIntranetFlags(array $userIds): void
	{
		if (!Loader::includeModule('intranet'))
		{
			return;
		}

		$users = [];
		foreach ($userIds as $userId)
		{
			$user = self::$loadedUsers[(int)$userId] ?? null;
			if (
				!$user instanceof self
				|| (int)$user->getId() <= 0
				|| $user->isIntranetUser !== null
				|| $user->isCurrentUser()
			)
			{
				continue;
			}

			$users[(int)$user->getId()] = $user;
		}

		if ($users === [])
		{
			return;
		}

		$rows = [];
		$query = UserTable::getList([
			'filter' => ['@ID' => array_keys($users)],
			'select' => self::getSelectForIntranetAttributes(),
		]);
		while ($row = $query->fetch())
		{
			$rows[(int)$row['ID']] = $row;
		}

		foreach ($users as $id => $user)
		{
			// A user the query brought nothing back for is left unwarmed: the single path answers for them
			// the way it always did, and the batch states only what it has actually read.
			if (isset($rows[$id]))
			{
				$user->resolveFlagsFromRow($rows[$id]);
			}
		}
	}

	/**
	 * Called with the intranet module already loaded.
	 * @return array|false Attributes an intranet check is built from, false when there are none to read.
	 */
	private function readIntranetAttributes()
	{
		if ($this->isCurrentUser()) // getting from cache
		{
			return CUser::GetByID($this->id)->Fetch();
		}

		// getting only necessary fields from db
		return UserTable::getList([
			'filter' => [
				'ID' => $this->id,
			],
			'select' => self::getSelectForIntranetAttributes(),
		])->fetch();
	}

	/**
	 * @return list<string>
	 */
	private static function getSelectForIntranetAttributes(): array
	{
		$select = [
			'ID',
			'EXTERNAL_AUTH_ID',
		];

		if (ModuleManager::isModuleInstalled('crm'))
		{
			$select[] = 'UF_USER_CRM_ENTITY';
		}

		return $select;
	}

	private function isCurrentUser(): bool
	{
		return (int)$this->id === (int)CurrentUser::get()->getId();
	}

	public function isCollaber(): bool
	{
		$userId = $this->getId();
		if (empty($userId))
		{
			return false;
		}

		return (new CollabService())->isCollaberUserById($userId);
	}

	/**
	 * Resolves isIntranetUser, isCrmEmail and isExternalAuthEmail from an already fetched row
	 * instead of a query. A flag whose key is absent from the row stays unresolved.
	 *
	 * @param array $userFields Array of user fields.
	 * @return void
	 * @throws \Bitrix\Main\LoaderException
	 */
	private function resolveFlagsFromRow(array $userFields): void
	{
		$this->isIntranetUser = false;
		if (!Loader::includeModule('intranet'))
		{
			return;
		}

		$intranetUser = \Bitrix\Intranet\Entity\User::initByArray($userFields);
		$this->isIntranetUser = $intranetUser->isIntranet();

		if (array_key_exists('UF_USER_CRM_ENTITY', $userFields))
		{
			$this->isCrmEmail = !empty($userFields['UF_USER_CRM_ENTITY']);
		}
		elseif (!ModuleManager::isModuleInstalled('crm'))
		{
			$this->isCrmEmail = false;
		}

		if ($this->isCrmEmail !== null && array_key_exists('EXTERNAL_AUTH_ID', $userFields))
		{
			$this->isExternalAuthEmail = !$this->isCrmEmail && $userFields['EXTERNAL_AUTH_ID'] === 'email';
		}
	}

	/**
	 * Checks is user from extranet.
	 * @return bool
	 */
	public function isExtranetUser()
	{
		return !$this->isIntranetUser() && Loader::includeModule('extranet');
	}

	/**
	 * Checks is simple email user.
	 * @return bool
	 */
	public function isExternalAuthEmail()
	{
		return !$this->isIntranetUser() && $this->isExternalAuthEmail;
	}

	/**
	 * Checks is a bot user.
	 * @return bool
	 */
	public function isBotUser():bool
	{
		return (bool)$this->isExternalBotUser;
	}

	public function setIsBotUser($value)
	{
		$this->isExternalBotUser = (bool)$value;
	}

	/**
	 * Checks is user has CRM junction.
	 * @return bool
	 */
	public function isCrmEmail()
	{
		return !$this->isIntranetUser() && $this->isCrmEmail;
	}

	public function getAvatarSrc($width = 58, $height = 58)
	{
		return Ui\Avatar::getPerson($this->personalPhoto, $width, $height);
	}

	/**
	 * Returns src of the picture uploaded by the user, without falling back to a default one.
	 */
	public function getOwnAvatarSrc(int $width = 58, int $height = 58): ?string
	{
		return Ui\Avatar::getSrc($this->personalPhoto, $width, $height) ?: null;
	}

	public function renderAvatar(int $width = 58, int $height = 58): string
	{
		if ($this->isCollaber())
		{
			return Ui\Avatar::renderCollaber($this->personalPhoto, $width, $height);
		}

		return Ui\Avatar::renderMember($this->personalPhoto, $width, $height);
	}

	/**
	 * Returns url of detail user page.
	 * @return string
	 */
	public function getDetailUrl()
	{
		if ($this->isExtranetUser())
		{

			$userPage = \COption::getOptionString("socialnetwork", "user_page", false, \CExtranet::getExtranetSiteID());
			if(!$userPage)
			{
				$userPage = '/extranet/contacts/personal/';
			}
		}
		else
		{
			$userPage = \COption::getOptionString("socialnetwork", "user_page", false, SITE_ID);
			if(!$userPage)
			{
				$userPage = SITE_DIR . 'company/personal/';
			}
		}

		return $userPage . 'user/' .  $this->id . '/';
	}

	public function isAdmin()
	{
		try
		{
			if(ModuleManager::isModuleInstalled('bitrix24') && Loader::includeModule('bitrix24'))
			{
				return \CBitrix24::isPortalAdmin($this->getId());
			}

			global $USER;

			// the flag belongs to whoever the session is authorized as, so the object and the session both have to
			// point at this very model: they may disagree after a re-authorization, {@see User::getSessionUserId()}
			$id = (int)$this->getId();
			if($USER instanceof \CUser && $id === (int)$USER->getId() && $id === self::getSessionUserId($USER))
			{
				return $USER->isAdmin();
			}

			$user = new \CUser();

			return in_array(1, $user->getUserGroup($this->getId()));
		}
		catch(\Exception $e)
		{}

		return false;
	}

	/**
	 * Determines if current user is admin.
	 *
	 * @deprecated
	 * @return bool
	 */
	public static function isCurrentUserAdmin()
	{
		global $USER;
		if(!$USER instanceof \CUser)
		{
			return false;
		}
		if($USER->isAdmin())
		{
			return true;
		}
		try
		{
			if(ModuleManager::isModuleInstalled('bitrix24') && Loader::includeModule('bitrix24'))
			{
				return \CBitrix24::isPortalAdmin($USER->getId());
			}
		}
		catch(\Exception $e)
		{
		}

		return false;
	}

	/**
	 * Resolves userId from parameter $user.
	 *
	 * @param \CUser|User|CurrentUser|int $user Different types: User model, CUser, id of user.
	 * @return int|null
	 */
	public static function resolveUserId($user)
	{
		if ($user instanceof CurrentUser)
		{
			return self::resolveUserId($user->getId());
		}
		if($user instanceof User)
		{
			return (int)$user->getId();
		}
		if ($user instanceof \CUser)
		{
			return (int)$user->getId();
		}
		elseif(is_numeric($user) && (int)$user > 0)
		{
			return (int)$user;
		}

		return null;
	}

	/**
	 * @internal
	 * @param      $userId
	 * @param User $currentFieldValue
	 * @return User|EmptyUser|SystemUser
	 */
	public static function getModelForReferenceField($userId, User $currentFieldValue = null)
	{
		if($userId === null)
		{
			return EmptyUser::create();
		}
		if(SystemUser::isSystemUserId($userId))
		{
			return SystemUser::create();
		}
		if(isset($currentFieldValue) && $userId == $currentFieldValue->getId())
		{
			return $currentFieldValue;
		}

		$user = User::loadById($userId);
		if(!$user)
		{
			return EmptyUser::create();
		}

		return $user;
	}
}