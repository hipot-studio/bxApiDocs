<?php

/* ORMENTITYANNOTATION:Bitrix\Intranet\UStat\DepartmentHourTable:intranet/lib/ustat/departmenthour.php */
namespace Bitrix\Intranet\UStat {
	/**
	 * EO_DepartmentHour
	 * @see \Bitrix\Intranet\UStat\DepartmentHourTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getDeptId()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour setDeptId(\int|\Bitrix\Main\DB\SqlExpression $deptId)
	 * @method bool hasDeptId()
	 * @method bool isDeptIdFilled()
	 * @method bool isDeptIdChanged()
	 * @method \Bitrix\Main\Type\DateTime getHour()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour setHour(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $hour)
	 * @method bool hasHour()
	 * @method bool isHourFilled()
	 * @method bool isHourChanged()
	 * @method \int getTotal()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour setTotal(\int|\Bitrix\Main\DB\SqlExpression $total)
	 * @method bool hasTotal()
	 * @method bool isTotalFilled()
	 * @method bool isTotalChanged()
	 * @method \int remindActualTotal()
	 * @method \int requireTotal()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour resetTotal()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour unsetTotal()
	 * @method \int fillTotal()
	 * @method \int getSocnet()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour setSocnet(\int|\Bitrix\Main\DB\SqlExpression $socnet)
	 * @method bool hasSocnet()
	 * @method bool isSocnetFilled()
	 * @method bool isSocnetChanged()
	 * @method \int remindActualSocnet()
	 * @method \int requireSocnet()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour resetSocnet()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour unsetSocnet()
	 * @method \int fillSocnet()
	 * @method \int getLikes()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour setLikes(\int|\Bitrix\Main\DB\SqlExpression $likes)
	 * @method bool hasLikes()
	 * @method bool isLikesFilled()
	 * @method bool isLikesChanged()
	 * @method \int remindActualLikes()
	 * @method \int requireLikes()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour resetLikes()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour unsetLikes()
	 * @method \int fillLikes()
	 * @method \int getTasks()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour setTasks(\int|\Bitrix\Main\DB\SqlExpression $tasks)
	 * @method bool hasTasks()
	 * @method bool isTasksFilled()
	 * @method bool isTasksChanged()
	 * @method \int remindActualTasks()
	 * @method \int requireTasks()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour resetTasks()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour unsetTasks()
	 * @method \int fillTasks()
	 * @method \int getIm()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour setIm(\int|\Bitrix\Main\DB\SqlExpression $im)
	 * @method bool hasIm()
	 * @method bool isImFilled()
	 * @method bool isImChanged()
	 * @method \int remindActualIm()
	 * @method \int requireIm()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour resetIm()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour unsetIm()
	 * @method \int fillIm()
	 * @method \int getDisk()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour setDisk(\int|\Bitrix\Main\DB\SqlExpression $disk)
	 * @method bool hasDisk()
	 * @method bool isDiskFilled()
	 * @method bool isDiskChanged()
	 * @method \int remindActualDisk()
	 * @method \int requireDisk()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour resetDisk()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour unsetDisk()
	 * @method \int fillDisk()
	 * @method \int getMobile()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour setMobile(\int|\Bitrix\Main\DB\SqlExpression $mobile)
	 * @method bool hasMobile()
	 * @method bool isMobileFilled()
	 * @method bool isMobileChanged()
	 * @method \int remindActualMobile()
	 * @method \int requireMobile()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour resetMobile()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour unsetMobile()
	 * @method \int fillMobile()
	 * @method \int getCrm()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour setCrm(\int|\Bitrix\Main\DB\SqlExpression $crm)
	 * @method bool hasCrm()
	 * @method bool isCrmFilled()
	 * @method bool isCrmChanged()
	 * @method \int remindActualCrm()
	 * @method \int requireCrm()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour resetCrm()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour unsetCrm()
	 * @method \int fillCrm()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour set($fieldName, $value)
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour reset($fieldName)
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Intranet\UStat\EO_DepartmentHour wakeUp($data)
	 */
	class EO_DepartmentHour {
		/* @var \Bitrix\Intranet\UStat\DepartmentHourTable */
		static public $dataClass = '\Bitrix\Intranet\UStat\DepartmentHourTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Intranet\UStat {
	/**
	 * EO_DepartmentHour_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getDeptIdList()
	 * @method \Bitrix\Main\Type\DateTime[] getHourList()
	 * @method \int[] getTotalList()
	 * @method \int[] fillTotal()
	 * @method \int[] getSocnetList()
	 * @method \int[] fillSocnet()
	 * @method \int[] getLikesList()
	 * @method \int[] fillLikes()
	 * @method \int[] getTasksList()
	 * @method \int[] fillTasks()
	 * @method \int[] getImList()
	 * @method \int[] fillIm()
	 * @method \int[] getDiskList()
	 * @method \int[] fillDisk()
	 * @method \int[] getMobileList()
	 * @method \int[] fillMobile()
	 * @method \int[] getCrmList()
	 * @method \int[] fillCrm()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Intranet\UStat\EO_DepartmentHour $object)
	 * @method bool has(\Bitrix\Intranet\UStat\EO_DepartmentHour $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour getByPrimary($primary)
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour[] getAll()
	 * @method bool remove(\Bitrix\Intranet\UStat\EO_DepartmentHour $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Intranet\UStat\EO_DepartmentHour_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour_Collection merge(?\Bitrix\Intranet\UStat\EO_DepartmentHour_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_DepartmentHour_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Intranet\UStat\DepartmentHourTable */
		static public $dataClass = '\Bitrix\Intranet\UStat\DepartmentHourTable';
	}
}
namespace Bitrix\Intranet\UStat {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_DepartmentHour_Result exec()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour fetchObject()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour_Collection fetchCollection()
	 */
	class EO_DepartmentHour_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour fetchObject()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour_Collection fetchCollection()
	 */
	class EO_DepartmentHour_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour createObject($setDefaultValues = true)
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour_Collection createCollection()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour wakeUpObject($row)
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentHour_Collection wakeUpCollection($rows)
	 */
	class EO_DepartmentHour_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Intranet\UStat\UserDayTable:intranet/lib/ustat/userday.php */
namespace Bitrix\Intranet\UStat {
	/**
	 * EO_UserDay
	 * @see \Bitrix\Intranet\UStat\UserDayTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getUserId()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \Bitrix\Main\Type\Date getDay()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay setDay(\Bitrix\Main\Type\Date|\Bitrix\Main\DB\SqlExpression $day)
	 * @method bool hasDay()
	 * @method bool isDayFilled()
	 * @method bool isDayChanged()
	 * @method \int getTotal()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay setTotal(\int|\Bitrix\Main\DB\SqlExpression $total)
	 * @method bool hasTotal()
	 * @method bool isTotalFilled()
	 * @method bool isTotalChanged()
	 * @method \int remindActualTotal()
	 * @method \int requireTotal()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay resetTotal()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay unsetTotal()
	 * @method \int fillTotal()
	 * @method \int getSocnet()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay setSocnet(\int|\Bitrix\Main\DB\SqlExpression $socnet)
	 * @method bool hasSocnet()
	 * @method bool isSocnetFilled()
	 * @method bool isSocnetChanged()
	 * @method \int remindActualSocnet()
	 * @method \int requireSocnet()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay resetSocnet()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay unsetSocnet()
	 * @method \int fillSocnet()
	 * @method \int getLikes()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay setLikes(\int|\Bitrix\Main\DB\SqlExpression $likes)
	 * @method bool hasLikes()
	 * @method bool isLikesFilled()
	 * @method bool isLikesChanged()
	 * @method \int remindActualLikes()
	 * @method \int requireLikes()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay resetLikes()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay unsetLikes()
	 * @method \int fillLikes()
	 * @method \int getTasks()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay setTasks(\int|\Bitrix\Main\DB\SqlExpression $tasks)
	 * @method bool hasTasks()
	 * @method bool isTasksFilled()
	 * @method bool isTasksChanged()
	 * @method \int remindActualTasks()
	 * @method \int requireTasks()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay resetTasks()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay unsetTasks()
	 * @method \int fillTasks()
	 * @method \int getIm()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay setIm(\int|\Bitrix\Main\DB\SqlExpression $im)
	 * @method bool hasIm()
	 * @method bool isImFilled()
	 * @method bool isImChanged()
	 * @method \int remindActualIm()
	 * @method \int requireIm()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay resetIm()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay unsetIm()
	 * @method \int fillIm()
	 * @method \int getDisk()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay setDisk(\int|\Bitrix\Main\DB\SqlExpression $disk)
	 * @method bool hasDisk()
	 * @method bool isDiskFilled()
	 * @method bool isDiskChanged()
	 * @method \int remindActualDisk()
	 * @method \int requireDisk()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay resetDisk()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay unsetDisk()
	 * @method \int fillDisk()
	 * @method \int getMobile()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay setMobile(\int|\Bitrix\Main\DB\SqlExpression $mobile)
	 * @method bool hasMobile()
	 * @method bool isMobileFilled()
	 * @method bool isMobileChanged()
	 * @method \int remindActualMobile()
	 * @method \int requireMobile()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay resetMobile()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay unsetMobile()
	 * @method \int fillMobile()
	 * @method \int getCrm()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay setCrm(\int|\Bitrix\Main\DB\SqlExpression $crm)
	 * @method bool hasCrm()
	 * @method bool isCrmFilled()
	 * @method bool isCrmChanged()
	 * @method \int remindActualCrm()
	 * @method \int requireCrm()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay resetCrm()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay unsetCrm()
	 * @method \int fillCrm()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Intranet\UStat\EO_UserDay set($fieldName, $value)
	 * @method \Bitrix\Intranet\UStat\EO_UserDay reset($fieldName)
	 * @method \Bitrix\Intranet\UStat\EO_UserDay unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Intranet\UStat\EO_UserDay wakeUp($data)
	 */
	class EO_UserDay {
		/* @var \Bitrix\Intranet\UStat\UserDayTable */
		static public $dataClass = '\Bitrix\Intranet\UStat\UserDayTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Intranet\UStat {
	/**
	 * EO_UserDay_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getUserIdList()
	 * @method \Bitrix\Main\Type\Date[] getDayList()
	 * @method \int[] getTotalList()
	 * @method \int[] fillTotal()
	 * @method \int[] getSocnetList()
	 * @method \int[] fillSocnet()
	 * @method \int[] getLikesList()
	 * @method \int[] fillLikes()
	 * @method \int[] getTasksList()
	 * @method \int[] fillTasks()
	 * @method \int[] getImList()
	 * @method \int[] fillIm()
	 * @method \int[] getDiskList()
	 * @method \int[] fillDisk()
	 * @method \int[] getMobileList()
	 * @method \int[] fillMobile()
	 * @method \int[] getCrmList()
	 * @method \int[] fillCrm()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Intranet\UStat\EO_UserDay $object)
	 * @method bool has(\Bitrix\Intranet\UStat\EO_UserDay $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Intranet\UStat\EO_UserDay getByPrimary($primary)
	 * @method \Bitrix\Intranet\UStat\EO_UserDay[] getAll()
	 * @method bool remove(\Bitrix\Intranet\UStat\EO_UserDay $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Intranet\UStat\EO_UserDay_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Intranet\UStat\EO_UserDay current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Intranet\UStat\EO_UserDay_Collection merge(?\Bitrix\Intranet\UStat\EO_UserDay_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_UserDay_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Intranet\UStat\UserDayTable */
		static public $dataClass = '\Bitrix\Intranet\UStat\UserDayTable';
	}
}
namespace Bitrix\Intranet\UStat {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_UserDay_Result exec()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay fetchObject()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay_Collection fetchCollection()
	 */
	class EO_UserDay_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Intranet\UStat\EO_UserDay fetchObject()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay_Collection fetchCollection()
	 */
	class EO_UserDay_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Intranet\UStat\EO_UserDay createObject($setDefaultValues = true)
	 * @method \Bitrix\Intranet\UStat\EO_UserDay_Collection createCollection()
	 * @method \Bitrix\Intranet\UStat\EO_UserDay wakeUpObject($row)
	 * @method \Bitrix\Intranet\UStat\EO_UserDay_Collection wakeUpCollection($rows)
	 */
	class EO_UserDay_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Intranet\UStat\DepartmentDayTable:intranet/lib/ustat/departmentday.php */
namespace Bitrix\Intranet\UStat {
	/**
	 * EO_DepartmentDay
	 * @see \Bitrix\Intranet\UStat\DepartmentDayTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getDeptId()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay setDeptId(\int|\Bitrix\Main\DB\SqlExpression $deptId)
	 * @method bool hasDeptId()
	 * @method bool isDeptIdFilled()
	 * @method bool isDeptIdChanged()
	 * @method \Bitrix\Main\Type\Date getDay()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay setDay(\Bitrix\Main\Type\Date|\Bitrix\Main\DB\SqlExpression $day)
	 * @method bool hasDay()
	 * @method bool isDayFilled()
	 * @method bool isDayChanged()
	 * @method \int getActiveUsers()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay setActiveUsers(\int|\Bitrix\Main\DB\SqlExpression $activeUsers)
	 * @method bool hasActiveUsers()
	 * @method bool isActiveUsersFilled()
	 * @method bool isActiveUsersChanged()
	 * @method \int remindActualActiveUsers()
	 * @method \int requireActiveUsers()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay resetActiveUsers()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay unsetActiveUsers()
	 * @method \int fillActiveUsers()
	 * @method \int getInvolvement()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay setInvolvement(\int|\Bitrix\Main\DB\SqlExpression $involvement)
	 * @method bool hasInvolvement()
	 * @method bool isInvolvementFilled()
	 * @method bool isInvolvementChanged()
	 * @method \int remindActualInvolvement()
	 * @method \int requireInvolvement()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay resetInvolvement()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay unsetInvolvement()
	 * @method \int fillInvolvement()
	 * @method \int getTotal()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay setTotal(\int|\Bitrix\Main\DB\SqlExpression $total)
	 * @method bool hasTotal()
	 * @method bool isTotalFilled()
	 * @method bool isTotalChanged()
	 * @method \int remindActualTotal()
	 * @method \int requireTotal()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay resetTotal()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay unsetTotal()
	 * @method \int fillTotal()
	 * @method \int getSocnet()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay setSocnet(\int|\Bitrix\Main\DB\SqlExpression $socnet)
	 * @method bool hasSocnet()
	 * @method bool isSocnetFilled()
	 * @method bool isSocnetChanged()
	 * @method \int remindActualSocnet()
	 * @method \int requireSocnet()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay resetSocnet()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay unsetSocnet()
	 * @method \int fillSocnet()
	 * @method \int getLikes()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay setLikes(\int|\Bitrix\Main\DB\SqlExpression $likes)
	 * @method bool hasLikes()
	 * @method bool isLikesFilled()
	 * @method bool isLikesChanged()
	 * @method \int remindActualLikes()
	 * @method \int requireLikes()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay resetLikes()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay unsetLikes()
	 * @method \int fillLikes()
	 * @method \int getTasks()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay setTasks(\int|\Bitrix\Main\DB\SqlExpression $tasks)
	 * @method bool hasTasks()
	 * @method bool isTasksFilled()
	 * @method bool isTasksChanged()
	 * @method \int remindActualTasks()
	 * @method \int requireTasks()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay resetTasks()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay unsetTasks()
	 * @method \int fillTasks()
	 * @method \int getIm()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay setIm(\int|\Bitrix\Main\DB\SqlExpression $im)
	 * @method bool hasIm()
	 * @method bool isImFilled()
	 * @method bool isImChanged()
	 * @method \int remindActualIm()
	 * @method \int requireIm()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay resetIm()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay unsetIm()
	 * @method \int fillIm()
	 * @method \int getDisk()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay setDisk(\int|\Bitrix\Main\DB\SqlExpression $disk)
	 * @method bool hasDisk()
	 * @method bool isDiskFilled()
	 * @method bool isDiskChanged()
	 * @method \int remindActualDisk()
	 * @method \int requireDisk()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay resetDisk()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay unsetDisk()
	 * @method \int fillDisk()
	 * @method \int getMobile()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay setMobile(\int|\Bitrix\Main\DB\SqlExpression $mobile)
	 * @method bool hasMobile()
	 * @method bool isMobileFilled()
	 * @method bool isMobileChanged()
	 * @method \int remindActualMobile()
	 * @method \int requireMobile()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay resetMobile()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay unsetMobile()
	 * @method \int fillMobile()
	 * @method \int getCrm()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay setCrm(\int|\Bitrix\Main\DB\SqlExpression $crm)
	 * @method bool hasCrm()
	 * @method bool isCrmFilled()
	 * @method bool isCrmChanged()
	 * @method \int remindActualCrm()
	 * @method \int requireCrm()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay resetCrm()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay unsetCrm()
	 * @method \int fillCrm()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay set($fieldName, $value)
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay reset($fieldName)
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Intranet\UStat\EO_DepartmentDay wakeUp($data)
	 */
	class EO_DepartmentDay {
		/* @var \Bitrix\Intranet\UStat\DepartmentDayTable */
		static public $dataClass = '\Bitrix\Intranet\UStat\DepartmentDayTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Intranet\UStat {
	/**
	 * EO_DepartmentDay_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getDeptIdList()
	 * @method \Bitrix\Main\Type\Date[] getDayList()
	 * @method \int[] getActiveUsersList()
	 * @method \int[] fillActiveUsers()
	 * @method \int[] getInvolvementList()
	 * @method \int[] fillInvolvement()
	 * @method \int[] getTotalList()
	 * @method \int[] fillTotal()
	 * @method \int[] getSocnetList()
	 * @method \int[] fillSocnet()
	 * @method \int[] getLikesList()
	 * @method \int[] fillLikes()
	 * @method \int[] getTasksList()
	 * @method \int[] fillTasks()
	 * @method \int[] getImList()
	 * @method \int[] fillIm()
	 * @method \int[] getDiskList()
	 * @method \int[] fillDisk()
	 * @method \int[] getMobileList()
	 * @method \int[] fillMobile()
	 * @method \int[] getCrmList()
	 * @method \int[] fillCrm()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Intranet\UStat\EO_DepartmentDay $object)
	 * @method bool has(\Bitrix\Intranet\UStat\EO_DepartmentDay $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay getByPrimary($primary)
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay[] getAll()
	 * @method bool remove(\Bitrix\Intranet\UStat\EO_DepartmentDay $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Intranet\UStat\EO_DepartmentDay_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay_Collection merge(?\Bitrix\Intranet\UStat\EO_DepartmentDay_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_DepartmentDay_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Intranet\UStat\DepartmentDayTable */
		static public $dataClass = '\Bitrix\Intranet\UStat\DepartmentDayTable';
	}
}
namespace Bitrix\Intranet\UStat {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_DepartmentDay_Result exec()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay fetchObject()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay_Collection fetchCollection()
	 */
	class EO_DepartmentDay_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay fetchObject()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay_Collection fetchCollection()
	 */
	class EO_DepartmentDay_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay createObject($setDefaultValues = true)
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay_Collection createCollection()
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay wakeUpObject($row)
	 * @method \Bitrix\Intranet\UStat\EO_DepartmentDay_Collection wakeUpCollection($rows)
	 */
	class EO_DepartmentDay_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Intranet\UStat\UserHourTable:intranet/lib/ustat/userhour.php */
namespace Bitrix\Intranet\UStat {
	/**
	 * EO_UserHour
	 * @see \Bitrix\Intranet\UStat\UserHourTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getUserId()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \Bitrix\Main\Type\DateTime getHour()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour setHour(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $hour)
	 * @method bool hasHour()
	 * @method bool isHourFilled()
	 * @method bool isHourChanged()
	 * @method \int getTotal()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour setTotal(\int|\Bitrix\Main\DB\SqlExpression $total)
	 * @method bool hasTotal()
	 * @method bool isTotalFilled()
	 * @method bool isTotalChanged()
	 * @method \int remindActualTotal()
	 * @method \int requireTotal()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour resetTotal()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour unsetTotal()
	 * @method \int fillTotal()
	 * @method \int getSocnet()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour setSocnet(\int|\Bitrix\Main\DB\SqlExpression $socnet)
	 * @method bool hasSocnet()
	 * @method bool isSocnetFilled()
	 * @method bool isSocnetChanged()
	 * @method \int remindActualSocnet()
	 * @method \int requireSocnet()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour resetSocnet()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour unsetSocnet()
	 * @method \int fillSocnet()
	 * @method \int getLikes()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour setLikes(\int|\Bitrix\Main\DB\SqlExpression $likes)
	 * @method bool hasLikes()
	 * @method bool isLikesFilled()
	 * @method bool isLikesChanged()
	 * @method \int remindActualLikes()
	 * @method \int requireLikes()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour resetLikes()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour unsetLikes()
	 * @method \int fillLikes()
	 * @method \int getTasks()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour setTasks(\int|\Bitrix\Main\DB\SqlExpression $tasks)
	 * @method bool hasTasks()
	 * @method bool isTasksFilled()
	 * @method bool isTasksChanged()
	 * @method \int remindActualTasks()
	 * @method \int requireTasks()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour resetTasks()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour unsetTasks()
	 * @method \int fillTasks()
	 * @method \int getIm()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour setIm(\int|\Bitrix\Main\DB\SqlExpression $im)
	 * @method bool hasIm()
	 * @method bool isImFilled()
	 * @method bool isImChanged()
	 * @method \int remindActualIm()
	 * @method \int requireIm()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour resetIm()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour unsetIm()
	 * @method \int fillIm()
	 * @method \int getDisk()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour setDisk(\int|\Bitrix\Main\DB\SqlExpression $disk)
	 * @method bool hasDisk()
	 * @method bool isDiskFilled()
	 * @method bool isDiskChanged()
	 * @method \int remindActualDisk()
	 * @method \int requireDisk()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour resetDisk()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour unsetDisk()
	 * @method \int fillDisk()
	 * @method \int getMobile()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour setMobile(\int|\Bitrix\Main\DB\SqlExpression $mobile)
	 * @method bool hasMobile()
	 * @method bool isMobileFilled()
	 * @method bool isMobileChanged()
	 * @method \int remindActualMobile()
	 * @method \int requireMobile()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour resetMobile()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour unsetMobile()
	 * @method \int fillMobile()
	 * @method \int getCrm()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour setCrm(\int|\Bitrix\Main\DB\SqlExpression $crm)
	 * @method bool hasCrm()
	 * @method bool isCrmFilled()
	 * @method bool isCrmChanged()
	 * @method \int remindActualCrm()
	 * @method \int requireCrm()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour resetCrm()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour unsetCrm()
	 * @method \int fillCrm()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Intranet\UStat\EO_UserHour set($fieldName, $value)
	 * @method \Bitrix\Intranet\UStat\EO_UserHour reset($fieldName)
	 * @method \Bitrix\Intranet\UStat\EO_UserHour unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Intranet\UStat\EO_UserHour wakeUp($data)
	 */
	class EO_UserHour {
		/* @var \Bitrix\Intranet\UStat\UserHourTable */
		static public $dataClass = '\Bitrix\Intranet\UStat\UserHourTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Intranet\UStat {
	/**
	 * EO_UserHour_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getUserIdList()
	 * @method \Bitrix\Main\Type\DateTime[] getHourList()
	 * @method \int[] getTotalList()
	 * @method \int[] fillTotal()
	 * @method \int[] getSocnetList()
	 * @method \int[] fillSocnet()
	 * @method \int[] getLikesList()
	 * @method \int[] fillLikes()
	 * @method \int[] getTasksList()
	 * @method \int[] fillTasks()
	 * @method \int[] getImList()
	 * @method \int[] fillIm()
	 * @method \int[] getDiskList()
	 * @method \int[] fillDisk()
	 * @method \int[] getMobileList()
	 * @method \int[] fillMobile()
	 * @method \int[] getCrmList()
	 * @method \int[] fillCrm()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Intranet\UStat\EO_UserHour $object)
	 * @method bool has(\Bitrix\Intranet\UStat\EO_UserHour $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Intranet\UStat\EO_UserHour getByPrimary($primary)
	 * @method \Bitrix\Intranet\UStat\EO_UserHour[] getAll()
	 * @method bool remove(\Bitrix\Intranet\UStat\EO_UserHour $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Intranet\UStat\EO_UserHour_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Intranet\UStat\EO_UserHour current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Intranet\UStat\EO_UserHour_Collection merge(?\Bitrix\Intranet\UStat\EO_UserHour_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_UserHour_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Intranet\UStat\UserHourTable */
		static public $dataClass = '\Bitrix\Intranet\UStat\UserHourTable';
	}
}
namespace Bitrix\Intranet\UStat {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_UserHour_Result exec()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour fetchObject()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour_Collection fetchCollection()
	 */
	class EO_UserHour_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Intranet\UStat\EO_UserHour fetchObject()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour_Collection fetchCollection()
	 */
	class EO_UserHour_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Intranet\UStat\EO_UserHour createObject($setDefaultValues = true)
	 * @method \Bitrix\Intranet\UStat\EO_UserHour_Collection createCollection()
	 * @method \Bitrix\Intranet\UStat\EO_UserHour wakeUpObject($row)
	 * @method \Bitrix\Intranet\UStat\EO_UserHour_Collection wakeUpCollection($rows)
	 */
	class EO_UserHour_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Intranet\CustomSection\Entity\CustomSectionTable:intranet/lib/customsection/entity/customsectiontable.php */
namespace Bitrix\Intranet\CustomSection\Entity {
	/**
	 * EO_CustomSection
	 * @see \Bitrix\Intranet\CustomSection\Entity\CustomSectionTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getCode()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection setCode(\string|\Bitrix\Main\DB\SqlExpression $code)
	 * @method bool hasCode()
	 * @method bool isCodeFilled()
	 * @method bool isCodeChanged()
	 * @method \string remindActualCode()
	 * @method \string requireCode()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection resetCode()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection unsetCode()
	 * @method \string fillCode()
	 * @method \string getTitle()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection setTitle(\string|\Bitrix\Main\DB\SqlExpression $title)
	 * @method bool hasTitle()
	 * @method bool isTitleFilled()
	 * @method bool isTitleChanged()
	 * @method \string remindActualTitle()
	 * @method \string requireTitle()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection resetTitle()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection unsetTitle()
	 * @method \string fillTitle()
	 * @method \string getModuleId()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection setModuleId(\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method \string remindActualModuleId()
	 * @method \string requireModuleId()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection resetModuleId()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection unsetModuleId()
	 * @method \string fillModuleId()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage_Collection getPages()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage_Collection requirePages()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage_Collection fillPages()
	 * @method bool hasPages()
	 * @method bool isPagesFilled()
	 * @method bool isPagesChanged()
	 * @method void addToPages(\Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage $customSectionPage)
	 * @method void removeFromPages(\Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage $customSectionPage)
	 * @method void removeAllPages()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection resetPages()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection unsetPages()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection set($fieldName, $value)
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection reset($fieldName)
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection wakeUp($data)
	 */
	class EO_CustomSection {
		/* @var \Bitrix\Intranet\CustomSection\Entity\CustomSectionTable */
		static public $dataClass = '\Bitrix\Intranet\CustomSection\Entity\CustomSectionTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Intranet\CustomSection\Entity {
	/**
	 * EO_CustomSection_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getCodeList()
	 * @method \string[] fillCode()
	 * @method \string[] getTitleList()
	 * @method \string[] fillTitle()
	 * @method \string[] getModuleIdList()
	 * @method \string[] fillModuleId()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage_Collection[] getPagesList()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage_Collection getPagesCollection()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage_Collection fillPages()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Intranet\CustomSection\Entity\EO_CustomSection $object)
	 * @method bool has(\Bitrix\Intranet\CustomSection\Entity\EO_CustomSection $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection getByPrimary($primary)
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection[] getAll()
	 * @method bool remove(\Bitrix\Intranet\CustomSection\Entity\EO_CustomSection $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection_Collection merge(?\Bitrix\Intranet\CustomSection\Entity\EO_CustomSection_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_CustomSection_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Intranet\CustomSection\Entity\CustomSectionTable */
		static public $dataClass = '\Bitrix\Intranet\CustomSection\Entity\CustomSectionTable';
	}
}
namespace Bitrix\Intranet\CustomSection\Entity {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_CustomSection_Result exec()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection fetchObject()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection_Collection fetchCollection()
	 */
	class EO_CustomSection_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection fetchObject()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection_Collection fetchCollection()
	 */
	class EO_CustomSection_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection createObject($setDefaultValues = true)
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection_Collection createCollection()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection wakeUpObject($row)
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection_Collection wakeUpCollection($rows)
	 */
	class EO_CustomSection_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Intranet\CustomSection\Entity\CustomSectionPageTable:intranet/lib/customsection/entity/customsectionpagetable.php */
namespace Bitrix\Intranet\CustomSection\Entity {
	/**
	 * EO_CustomSectionPage
	 * @see \Bitrix\Intranet\CustomSection\Entity\CustomSectionPageTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getCustomSectionId()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage setCustomSectionId(\int|\Bitrix\Main\DB\SqlExpression $customSectionId)
	 * @method bool hasCustomSectionId()
	 * @method bool isCustomSectionIdFilled()
	 * @method bool isCustomSectionIdChanged()
	 * @method \int remindActualCustomSectionId()
	 * @method \int requireCustomSectionId()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage resetCustomSectionId()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage unsetCustomSectionId()
	 * @method \int fillCustomSectionId()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection getCustomSection()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection remindActualCustomSection()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection requireCustomSection()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage setCustomSection(\Bitrix\Intranet\CustomSection\Entity\EO_CustomSection $object)
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage resetCustomSection()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage unsetCustomSection()
	 * @method bool hasCustomSection()
	 * @method bool isCustomSectionFilled()
	 * @method bool isCustomSectionChanged()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection fillCustomSection()
	 * @method \string getCode()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage setCode(\string|\Bitrix\Main\DB\SqlExpression $code)
	 * @method bool hasCode()
	 * @method bool isCodeFilled()
	 * @method bool isCodeChanged()
	 * @method \string remindActualCode()
	 * @method \string requireCode()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage resetCode()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage unsetCode()
	 * @method \string fillCode()
	 * @method \string getTitle()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage setTitle(\string|\Bitrix\Main\DB\SqlExpression $title)
	 * @method bool hasTitle()
	 * @method bool isTitleFilled()
	 * @method bool isTitleChanged()
	 * @method \string remindActualTitle()
	 * @method \string requireTitle()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage resetTitle()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage unsetTitle()
	 * @method \string fillTitle()
	 * @method \int getSort()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage setSort(\int|\Bitrix\Main\DB\SqlExpression $sort)
	 * @method bool hasSort()
	 * @method bool isSortFilled()
	 * @method bool isSortChanged()
	 * @method \int remindActualSort()
	 * @method \int requireSort()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage resetSort()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage unsetSort()
	 * @method \int fillSort()
	 * @method \string getModuleId()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage setModuleId(\string|\Bitrix\Main\DB\SqlExpression $moduleId)
	 * @method bool hasModuleId()
	 * @method bool isModuleIdFilled()
	 * @method bool isModuleIdChanged()
	 * @method \string remindActualModuleId()
	 * @method \string requireModuleId()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage resetModuleId()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage unsetModuleId()
	 * @method \string fillModuleId()
	 * @method \string getSettings()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage setSettings(\string|\Bitrix\Main\DB\SqlExpression $settings)
	 * @method bool hasSettings()
	 * @method bool isSettingsFilled()
	 * @method bool isSettingsChanged()
	 * @method \string remindActualSettings()
	 * @method \string requireSettings()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage resetSettings()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage unsetSettings()
	 * @method \string fillSettings()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage set($fieldName, $value)
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage reset($fieldName)
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage wakeUp($data)
	 */
	class EO_CustomSectionPage {
		/* @var \Bitrix\Intranet\CustomSection\Entity\CustomSectionPageTable */
		static public $dataClass = '\Bitrix\Intranet\CustomSection\Entity\CustomSectionPageTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Intranet\CustomSection\Entity {
	/**
	 * EO_CustomSectionPage_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getCustomSectionIdList()
	 * @method \int[] fillCustomSectionId()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection[] getCustomSectionList()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage_Collection getCustomSectionCollection()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSection_Collection fillCustomSection()
	 * @method \string[] getCodeList()
	 * @method \string[] fillCode()
	 * @method \string[] getTitleList()
	 * @method \string[] fillTitle()
	 * @method \int[] getSortList()
	 * @method \int[] fillSort()
	 * @method \string[] getModuleIdList()
	 * @method \string[] fillModuleId()
	 * @method \string[] getSettingsList()
	 * @method \string[] fillSettings()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage $object)
	 * @method bool has(\Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage getByPrimary($primary)
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage[] getAll()
	 * @method bool remove(\Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage_Collection merge(?\Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_CustomSectionPage_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Intranet\CustomSection\Entity\CustomSectionPageTable */
		static public $dataClass = '\Bitrix\Intranet\CustomSection\Entity\CustomSectionPageTable';
	}
}
namespace Bitrix\Intranet\CustomSection\Entity {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_CustomSectionPage_Result exec()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage fetchObject()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage_Collection fetchCollection()
	 */
	class EO_CustomSectionPage_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage fetchObject()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage_Collection fetchCollection()
	 */
	class EO_CustomSectionPage_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage createObject($setDefaultValues = true)
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage_Collection createCollection()
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage wakeUpObject($row)
	 * @method \Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage_Collection wakeUpCollection($rows)
	 */
	class EO_CustomSectionPage_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Intranet\RatingSubordinateTable:intranet/lib/ratingsubordinate.php */
namespace Bitrix\Intranet {
	/**
	 * EO_RatingSubordinate
	 * @see \Bitrix\Intranet\RatingSubordinateTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Intranet\EO_RatingSubordinate setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getRatingId()
	 * @method \Bitrix\Intranet\EO_RatingSubordinate setRatingId(\int|\Bitrix\Main\DB\SqlExpression $ratingId)
	 * @method bool hasRatingId()
	 * @method bool isRatingIdFilled()
	 * @method bool isRatingIdChanged()
	 * @method \int remindActualRatingId()
	 * @method \int requireRatingId()
	 * @method \Bitrix\Intranet\EO_RatingSubordinate resetRatingId()
	 * @method \Bitrix\Intranet\EO_RatingSubordinate unsetRatingId()
	 * @method \int fillRatingId()
	 * @method \int getEntityId()
	 * @method \Bitrix\Intranet\EO_RatingSubordinate setEntityId(\int|\Bitrix\Main\DB\SqlExpression $entityId)
	 * @method bool hasEntityId()
	 * @method bool isEntityIdFilled()
	 * @method bool isEntityIdChanged()
	 * @method \int remindActualEntityId()
	 * @method \int requireEntityId()
	 * @method \Bitrix\Intranet\EO_RatingSubordinate resetEntityId()
	 * @method \Bitrix\Intranet\EO_RatingSubordinate unsetEntityId()
	 * @method \int fillEntityId()
	 * @method \float getVotes()
	 * @method \Bitrix\Intranet\EO_RatingSubordinate setVotes(\float|\Bitrix\Main\DB\SqlExpression $votes)
	 * @method bool hasVotes()
	 * @method bool isVotesFilled()
	 * @method bool isVotesChanged()
	 * @method \float remindActualVotes()
	 * @method \float requireVotes()
	 * @method \Bitrix\Intranet\EO_RatingSubordinate resetVotes()
	 * @method \Bitrix\Intranet\EO_RatingSubordinate unsetVotes()
	 * @method \float fillVotes()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Intranet\EO_RatingSubordinate set($fieldName, $value)
	 * @method \Bitrix\Intranet\EO_RatingSubordinate reset($fieldName)
	 * @method \Bitrix\Intranet\EO_RatingSubordinate unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Intranet\EO_RatingSubordinate wakeUp($data)
	 */
	class EO_RatingSubordinate {
		/* @var \Bitrix\Intranet\RatingSubordinateTable */
		static public $dataClass = '\Bitrix\Intranet\RatingSubordinateTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Intranet {
	/**
	 * EO_RatingSubordinate_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getRatingIdList()
	 * @method \int[] fillRatingId()
	 * @method \int[] getEntityIdList()
	 * @method \int[] fillEntityId()
	 * @method \float[] getVotesList()
	 * @method \float[] fillVotes()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Intranet\EO_RatingSubordinate $object)
	 * @method bool has(\Bitrix\Intranet\EO_RatingSubordinate $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Intranet\EO_RatingSubordinate getByPrimary($primary)
	 * @method \Bitrix\Intranet\EO_RatingSubordinate[] getAll()
	 * @method bool remove(\Bitrix\Intranet\EO_RatingSubordinate $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Intranet\EO_RatingSubordinate_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Intranet\EO_RatingSubordinate current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Intranet\EO_RatingSubordinate_Collection merge(?\Bitrix\Intranet\EO_RatingSubordinate_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_RatingSubordinate_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Intranet\RatingSubordinateTable */
		static public $dataClass = '\Bitrix\Intranet\RatingSubordinateTable';
	}
}
namespace Bitrix\Intranet {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_RatingSubordinate_Result exec()
	 * @method \Bitrix\Intranet\EO_RatingSubordinate fetchObject()
	 * @method \Bitrix\Intranet\EO_RatingSubordinate_Collection fetchCollection()
	 */
	class EO_RatingSubordinate_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Intranet\EO_RatingSubordinate fetchObject()
	 * @method \Bitrix\Intranet\EO_RatingSubordinate_Collection fetchCollection()
	 */
	class EO_RatingSubordinate_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Intranet\EO_RatingSubordinate createObject($setDefaultValues = true)
	 * @method \Bitrix\Intranet\EO_RatingSubordinate_Collection createCollection()
	 * @method \Bitrix\Intranet\EO_RatingSubordinate wakeUpObject($row)
	 * @method \Bitrix\Intranet\EO_RatingSubordinate_Collection wakeUpCollection($rows)
	 */
	class EO_RatingSubordinate_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Intranet\Table\InvitationLinkTable:intranet/lib/table/invitationlinktable.php */
namespace Bitrix\Intranet\Table {
	/**
	 * InvitationLink
	 * @see \Bitrix\Intranet\Table\InvitationLinkTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getEntityType()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink setEntityType(\string|\Bitrix\Main\DB\SqlExpression $entityType)
	 * @method bool hasEntityType()
	 * @method bool isEntityTypeFilled()
	 * @method bool isEntityTypeChanged()
	 * @method \string remindActualEntityType()
	 * @method \string requireEntityType()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink resetEntityType()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink unsetEntityType()
	 * @method \string fillEntityType()
	 * @method \int getEntityId()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink setEntityId(\int|\Bitrix\Main\DB\SqlExpression $entityId)
	 * @method bool hasEntityId()
	 * @method bool isEntityIdFilled()
	 * @method bool isEntityIdChanged()
	 * @method \int remindActualEntityId()
	 * @method \int requireEntityId()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink resetEntityId()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink unsetEntityId()
	 * @method \int fillEntityId()
	 * @method \string getCode()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink setCode(\string|\Bitrix\Main\DB\SqlExpression $code)
	 * @method bool hasCode()
	 * @method bool isCodeFilled()
	 * @method bool isCodeChanged()
	 * @method \string remindActualCode()
	 * @method \string requireCode()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink resetCode()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink unsetCode()
	 * @method \string fillCode()
	 * @method null|\int getCreatedBy()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink setCreatedBy(null|\int|\Bitrix\Main\DB\SqlExpression $createdBy)
	 * @method bool hasCreatedBy()
	 * @method bool isCreatedByFilled()
	 * @method bool isCreatedByChanged()
	 * @method null|\int remindActualCreatedBy()
	 * @method null|\int requireCreatedBy()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink resetCreatedBy()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink unsetCreatedBy()
	 * @method null|\int fillCreatedBy()
	 * @method null|\Bitrix\Main\Type\DateTime getCreatedAt()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink setCreatedAt(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdAt)
	 * @method bool hasCreatedAt()
	 * @method bool isCreatedAtFilled()
	 * @method bool isCreatedAtChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualCreatedAt()
	 * @method null|\Bitrix\Main\Type\DateTime requireCreatedAt()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink resetCreatedAt()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink unsetCreatedAt()
	 * @method null|\Bitrix\Main\Type\DateTime fillCreatedAt()
	 * @method null|\Bitrix\Main\Type\DateTime getExpiredAt()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink setExpiredAt(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $expiredAt)
	 * @method bool hasExpiredAt()
	 * @method bool isExpiredAtFilled()
	 * @method bool isExpiredAtChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualExpiredAt()
	 * @method null|\Bitrix\Main\Type\DateTime requireExpiredAt()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink resetExpiredAt()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink unsetExpiredAt()
	 * @method null|\Bitrix\Main\Type\DateTime fillExpiredAt()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink set($fieldName, $value)
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink reset($fieldName)
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Intranet\Internal\Model\InvitationLink wakeUp($data)
	 */
	class EO_InvitationLink {
		/* @var \Bitrix\Intranet\Table\InvitationLinkTable */
		static public $dataClass = '\Bitrix\Intranet\Table\InvitationLinkTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Intranet\Table {
	/**
	 * EO_InvitationLink_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getEntityTypeList()
	 * @method \string[] fillEntityType()
	 * @method \int[] getEntityIdList()
	 * @method \int[] fillEntityId()
	 * @method \string[] getCodeList()
	 * @method \string[] fillCode()
	 * @method null|\int[] getCreatedByList()
	 * @method null|\int[] fillCreatedBy()
	 * @method null|\Bitrix\Main\Type\DateTime[] getCreatedAtList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillCreatedAt()
	 * @method null|\Bitrix\Main\Type\DateTime[] getExpiredAtList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillExpiredAt()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Intranet\Internal\Model\InvitationLink $object)
	 * @method bool has(\Bitrix\Intranet\Internal\Model\InvitationLink $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink getByPrimary($primary)
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink[] getAll()
	 * @method bool remove(\Bitrix\Intranet\Internal\Model\InvitationLink $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Intranet\Table\EO_InvitationLink_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Intranet\Table\EO_InvitationLink_Collection merge(?\Bitrix\Intranet\Table\EO_InvitationLink_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_InvitationLink_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Intranet\Table\InvitationLinkTable */
		static public $dataClass = '\Bitrix\Intranet\Table\InvitationLinkTable';
	}
}
namespace Bitrix\Intranet\Table {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_InvitationLink_Result exec()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink fetchObject()
	 * @method \Bitrix\Intranet\Table\EO_InvitationLink_Collection fetchCollection()
	 */
	class EO_InvitationLink_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink fetchObject()
	 * @method \Bitrix\Intranet\Table\EO_InvitationLink_Collection fetchCollection()
	 */
	class EO_InvitationLink_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink createObject($setDefaultValues = true)
	 * @method \Bitrix\Intranet\Table\EO_InvitationLink_Collection createCollection()
	 * @method \Bitrix\Intranet\Internal\Model\InvitationLink wakeUpObject($row)
	 * @method \Bitrix\Intranet\Table\EO_InvitationLink_Collection wakeUpCollection($rows)
	 */
	class EO_InvitationLink_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Intranet\Internal\Model\VerifyNumberCodeTable:intranet/lib/internal/model/verifynumbercodetable.php */
namespace Bitrix\Intranet\Internal\Model {
	/**
	 * EO_VerifyNumberCode
	 * @see \Bitrix\Intranet\Internal\Model\VerifyNumberCodeTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getUserId()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode resetUserId()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode unsetUserId()
	 * @method \int fillUserId()
	 * @method \string getPhoneNumber()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode setPhoneNumber(\string|\Bitrix\Main\DB\SqlExpression $phoneNumber)
	 * @method bool hasPhoneNumber()
	 * @method bool isPhoneNumberFilled()
	 * @method bool isPhoneNumberChanged()
	 * @method \string remindActualPhoneNumber()
	 * @method \string requirePhoneNumber()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode resetPhoneNumber()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode unsetPhoneNumber()
	 * @method \string fillPhoneNumber()
	 * @method \string getCode()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode setCode(\string|\Bitrix\Main\DB\SqlExpression $code)
	 * @method bool hasCode()
	 * @method bool isCodeFilled()
	 * @method bool isCodeChanged()
	 * @method \string remindActualCode()
	 * @method \string requireCode()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode resetCode()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode unsetCode()
	 * @method \string fillCode()
	 * @method \Bitrix\Main\Type\DateTime getCreatedAt()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode setCreatedAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $createdAt)
	 * @method bool hasCreatedAt()
	 * @method bool isCreatedAtFilled()
	 * @method bool isCreatedAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime requireCreatedAt()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode resetCreatedAt()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode unsetCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime fillCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime getLastSentAt()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode setLastSentAt(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $lastSentAt)
	 * @method bool hasLastSentAt()
	 * @method bool isLastSentAtFilled()
	 * @method bool isLastSentAtChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualLastSentAt()
	 * @method \Bitrix\Main\Type\DateTime requireLastSentAt()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode resetLastSentAt()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode unsetLastSentAt()
	 * @method \Bitrix\Main\Type\DateTime fillLastSentAt()
	 * @method null|\Bitrix\Main\Type\DateTime getConfirmedAt()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode setConfirmedAt(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $confirmedAt)
	 * @method bool hasConfirmedAt()
	 * @method bool isConfirmedAtFilled()
	 * @method bool isConfirmedAtChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualConfirmedAt()
	 * @method null|\Bitrix\Main\Type\DateTime requireConfirmedAt()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode resetConfirmedAt()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode unsetConfirmedAt()
	 * @method null|\Bitrix\Main\Type\DateTime fillConfirmedAt()
	 * @method \int getAttempts()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode setAttempts(\int|\Bitrix\Main\DB\SqlExpression $attempts)
	 * @method bool hasAttempts()
	 * @method bool isAttemptsFilled()
	 * @method bool isAttemptsChanged()
	 * @method \int remindActualAttempts()
	 * @method \int requireAttempts()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode resetAttempts()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode unsetAttempts()
	 * @method \int fillAttempts()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode set($fieldName, $value)
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode reset($fieldName)
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode wakeUp($data)
	 */
	class EO_VerifyNumberCode {
		/* @var \Bitrix\Intranet\Internal\Model\VerifyNumberCodeTable */
		static public $dataClass = '\Bitrix\Intranet\Internal\Model\VerifyNumberCodeTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Intranet\Internal\Model {
	/**
	 * EO_VerifyNumberCode_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \string[] getPhoneNumberList()
	 * @method \string[] fillPhoneNumber()
	 * @method \string[] getCodeList()
	 * @method \string[] fillCode()
	 * @method \Bitrix\Main\Type\DateTime[] getCreatedAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillCreatedAt()
	 * @method \Bitrix\Main\Type\DateTime[] getLastSentAtList()
	 * @method \Bitrix\Main\Type\DateTime[] fillLastSentAt()
	 * @method null|\Bitrix\Main\Type\DateTime[] getConfirmedAtList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillConfirmedAt()
	 * @method \int[] getAttemptsList()
	 * @method \int[] fillAttempts()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode $object)
	 * @method bool has(\Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode getByPrimary($primary)
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode[] getAll()
	 * @method bool remove(\Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode_Collection merge(?\Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_VerifyNumberCode_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Intranet\Internal\Model\VerifyNumberCodeTable */
		static public $dataClass = '\Bitrix\Intranet\Internal\Model\VerifyNumberCodeTable';
	}
}
namespace Bitrix\Intranet\Internal\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_VerifyNumberCode_Result exec()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode fetchObject()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode_Collection fetchCollection()
	 */
	class EO_VerifyNumberCode_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode fetchObject()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode_Collection fetchCollection()
	 */
	class EO_VerifyNumberCode_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode createObject($setDefaultValues = true)
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode_Collection createCollection()
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode wakeUpObject($row)
	 * @method \Bitrix\Intranet\Internal\Model\EO_VerifyNumberCode_Collection wakeUpCollection($rows)
	 */
	class EO_VerifyNumberCode_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Intranet\Internals\ThemeTable:intranet/lib/internals/theme.php */
namespace Bitrix\Intranet\Internals {
	/**
	 * EO_Theme
	 * @see \Bitrix\Intranet\Internals\ThemeTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Intranet\Internals\EO_Theme setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getThemeId()
	 * @method \Bitrix\Intranet\Internals\EO_Theme setThemeId(\string|\Bitrix\Main\DB\SqlExpression $themeId)
	 * @method bool hasThemeId()
	 * @method bool isThemeIdFilled()
	 * @method bool isThemeIdChanged()
	 * @method \string remindActualThemeId()
	 * @method \string requireThemeId()
	 * @method \Bitrix\Intranet\Internals\EO_Theme resetThemeId()
	 * @method \Bitrix\Intranet\Internals\EO_Theme unsetThemeId()
	 * @method \string fillThemeId()
	 * @method \int getUserId()
	 * @method \Bitrix\Intranet\Internals\EO_Theme setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\Intranet\Internals\EO_Theme resetUserId()
	 * @method \Bitrix\Intranet\Internals\EO_Theme unsetUserId()
	 * @method \int fillUserId()
	 * @method \string getEntityType()
	 * @method \Bitrix\Intranet\Internals\EO_Theme setEntityType(\string|\Bitrix\Main\DB\SqlExpression $entityType)
	 * @method bool hasEntityType()
	 * @method bool isEntityTypeFilled()
	 * @method bool isEntityTypeChanged()
	 * @method \string remindActualEntityType()
	 * @method \string requireEntityType()
	 * @method \Bitrix\Intranet\Internals\EO_Theme resetEntityType()
	 * @method \Bitrix\Intranet\Internals\EO_Theme unsetEntityType()
	 * @method \string fillEntityType()
	 * @method \int getEntityId()
	 * @method \Bitrix\Intranet\Internals\EO_Theme setEntityId(\int|\Bitrix\Main\DB\SqlExpression $entityId)
	 * @method bool hasEntityId()
	 * @method bool isEntityIdFilled()
	 * @method bool isEntityIdChanged()
	 * @method \int remindActualEntityId()
	 * @method \int requireEntityId()
	 * @method \Bitrix\Intranet\Internals\EO_Theme resetEntityId()
	 * @method \Bitrix\Intranet\Internals\EO_Theme unsetEntityId()
	 * @method \int fillEntityId()
	 * @method \string getContext()
	 * @method \Bitrix\Intranet\Internals\EO_Theme setContext(\string|\Bitrix\Main\DB\SqlExpression $context)
	 * @method bool hasContext()
	 * @method bool isContextFilled()
	 * @method bool isContextChanged()
	 * @method \string remindActualContext()
	 * @method \string requireContext()
	 * @method \Bitrix\Intranet\Internals\EO_Theme resetContext()
	 * @method \Bitrix\Intranet\Internals\EO_Theme unsetContext()
	 * @method \string fillContext()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Intranet\Internals\EO_Theme set($fieldName, $value)
	 * @method \Bitrix\Intranet\Internals\EO_Theme reset($fieldName)
	 * @method \Bitrix\Intranet\Internals\EO_Theme unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Intranet\Internals\EO_Theme wakeUp($data)
	 */
	class EO_Theme {
		/* @var \Bitrix\Intranet\Internals\ThemeTable */
		static public $dataClass = '\Bitrix\Intranet\Internals\ThemeTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Intranet\Internals {
	/**
	 * EO_Theme_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getThemeIdList()
	 * @method \string[] fillThemeId()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \string[] getEntityTypeList()
	 * @method \string[] fillEntityType()
	 * @method \int[] getEntityIdList()
	 * @method \int[] fillEntityId()
	 * @method \string[] getContextList()
	 * @method \string[] fillContext()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Intranet\Internals\EO_Theme $object)
	 * @method bool has(\Bitrix\Intranet\Internals\EO_Theme $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Intranet\Internals\EO_Theme getByPrimary($primary)
	 * @method \Bitrix\Intranet\Internals\EO_Theme[] getAll()
	 * @method bool remove(\Bitrix\Intranet\Internals\EO_Theme $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Intranet\Internals\EO_Theme_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Intranet\Internals\EO_Theme current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Intranet\Internals\EO_Theme_Collection merge(?\Bitrix\Intranet\Internals\EO_Theme_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_Theme_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Intranet\Internals\ThemeTable */
		static public $dataClass = '\Bitrix\Intranet\Internals\ThemeTable';
	}
}
namespace Bitrix\Intranet\Internals {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Theme_Result exec()
	 * @method \Bitrix\Intranet\Internals\EO_Theme fetchObject()
	 * @method \Bitrix\Intranet\Internals\EO_Theme_Collection fetchCollection()
	 */
	class EO_Theme_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Intranet\Internals\EO_Theme fetchObject()
	 * @method \Bitrix\Intranet\Internals\EO_Theme_Collection fetchCollection()
	 */
	class EO_Theme_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Intranet\Internals\EO_Theme createObject($setDefaultValues = true)
	 * @method \Bitrix\Intranet\Internals\EO_Theme_Collection createCollection()
	 * @method \Bitrix\Intranet\Internals\EO_Theme wakeUpObject($row)
	 * @method \Bitrix\Intranet\Internals\EO_Theme_Collection wakeUpCollection($rows)
	 */
	class EO_Theme_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Intranet\Internals\QueueTable:intranet/lib/internals/queue.php */
namespace Bitrix\Intranet\Internals {
	/**
	 * EO_Queue
	 * @see \Bitrix\Intranet\Internals\QueueTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string getEntityType()
	 * @method \Bitrix\Intranet\Internals\EO_Queue setEntityType(\string|\Bitrix\Main\DB\SqlExpression $entityType)
	 * @method bool hasEntityType()
	 * @method bool isEntityTypeFilled()
	 * @method bool isEntityTypeChanged()
	 * @method \string getEntityId()
	 * @method \Bitrix\Intranet\Internals\EO_Queue setEntityId(\string|\Bitrix\Main\DB\SqlExpression $entityId)
	 * @method bool hasEntityId()
	 * @method bool isEntityIdFilled()
	 * @method bool isEntityIdChanged()
	 * @method \string getLastItem()
	 * @method \Bitrix\Intranet\Internals\EO_Queue setLastItem(\string|\Bitrix\Main\DB\SqlExpression $lastItem)
	 * @method bool hasLastItem()
	 * @method bool isLastItemFilled()
	 * @method bool isLastItemChanged()
	 * @method \string remindActualLastItem()
	 * @method \string requireLastItem()
	 * @method \Bitrix\Intranet\Internals\EO_Queue resetLastItem()
	 * @method \Bitrix\Intranet\Internals\EO_Queue unsetLastItem()
	 * @method \string fillLastItem()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Intranet\Internals\EO_Queue set($fieldName, $value)
	 * @method \Bitrix\Intranet\Internals\EO_Queue reset($fieldName)
	 * @method \Bitrix\Intranet\Internals\EO_Queue unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Intranet\Internals\EO_Queue wakeUp($data)
	 */
	class EO_Queue {
		/* @var \Bitrix\Intranet\Internals\QueueTable */
		static public $dataClass = '\Bitrix\Intranet\Internals\QueueTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Intranet\Internals {
	/**
	 * EO_Queue_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \string[] getEntityTypeList()
	 * @method \string[] getEntityIdList()
	 * @method \string[] getLastItemList()
	 * @method \string[] fillLastItem()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Intranet\Internals\EO_Queue $object)
	 * @method bool has(\Bitrix\Intranet\Internals\EO_Queue $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Intranet\Internals\EO_Queue getByPrimary($primary)
	 * @method \Bitrix\Intranet\Internals\EO_Queue[] getAll()
	 * @method bool remove(\Bitrix\Intranet\Internals\EO_Queue $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Intranet\Internals\EO_Queue_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Intranet\Internals\EO_Queue current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Intranet\Internals\EO_Queue_Collection merge(?\Bitrix\Intranet\Internals\EO_Queue_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_Queue_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Intranet\Internals\QueueTable */
		static public $dataClass = '\Bitrix\Intranet\Internals\QueueTable';
	}
}
namespace Bitrix\Intranet\Internals {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Queue_Result exec()
	 * @method \Bitrix\Intranet\Internals\EO_Queue fetchObject()
	 * @method \Bitrix\Intranet\Internals\EO_Queue_Collection fetchCollection()
	 */
	class EO_Queue_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Intranet\Internals\EO_Queue fetchObject()
	 * @method \Bitrix\Intranet\Internals\EO_Queue_Collection fetchCollection()
	 */
	class EO_Queue_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Intranet\Internals\EO_Queue createObject($setDefaultValues = true)
	 * @method \Bitrix\Intranet\Internals\EO_Queue_Collection createCollection()
	 * @method \Bitrix\Intranet\Internals\EO_Queue wakeUpObject($row)
	 * @method \Bitrix\Intranet\Internals\EO_Queue_Collection wakeUpCollection($rows)
	 */
	class EO_Queue_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Intranet\Internals\InvitationTable:intranet/lib/internals/invitation.php */
namespace Bitrix\Intranet\Internals {
	/**
	 * EO_Invitation
	 * @see \Bitrix\Intranet\Internals\InvitationTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getUserId()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation resetUserId()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation unsetUserId()
	 * @method \int fillUserId()
	 * @method \int getOriginatorId()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation setOriginatorId(\int|\Bitrix\Main\DB\SqlExpression $originatorId)
	 * @method bool hasOriginatorId()
	 * @method bool isOriginatorIdFilled()
	 * @method bool isOriginatorIdChanged()
	 * @method \int remindActualOriginatorId()
	 * @method \int requireOriginatorId()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation resetOriginatorId()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation unsetOriginatorId()
	 * @method \int fillOriginatorId()
	 * @method \string getInvitationType()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation setInvitationType(\string|\Bitrix\Main\DB\SqlExpression $invitationType)
	 * @method bool hasInvitationType()
	 * @method bool isInvitationTypeFilled()
	 * @method bool isInvitationTypeChanged()
	 * @method \string remindActualInvitationType()
	 * @method \string requireInvitationType()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation resetInvitationType()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation unsetInvitationType()
	 * @method \string fillInvitationType()
	 * @method \Bitrix\Main\Type\DateTime getDateCreate()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation setDateCreate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateCreate)
	 * @method bool hasDateCreate()
	 * @method bool isDateCreateFilled()
	 * @method bool isDateCreateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualDateCreate()
	 * @method \Bitrix\Main\Type\DateTime requireDateCreate()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation resetDateCreate()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation unsetDateCreate()
	 * @method \Bitrix\Main\Type\DateTime fillDateCreate()
	 * @method \boolean getInitialized()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation setInitialized(\boolean|\Bitrix\Main\DB\SqlExpression $initialized)
	 * @method bool hasInitialized()
	 * @method bool isInitializedFilled()
	 * @method bool isInitializedChanged()
	 * @method \boolean remindActualInitialized()
	 * @method \boolean requireInitialized()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation resetInitialized()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation unsetInitialized()
	 * @method \boolean fillInitialized()
	 * @method \boolean getIsMass()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation setIsMass(\boolean|\Bitrix\Main\DB\SqlExpression $isMass)
	 * @method bool hasIsMass()
	 * @method bool isIsMassFilled()
	 * @method bool isIsMassChanged()
	 * @method \boolean remindActualIsMass()
	 * @method \boolean requireIsMass()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation resetIsMass()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation unsetIsMass()
	 * @method \boolean fillIsMass()
	 * @method \boolean getIsDepartment()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation setIsDepartment(\boolean|\Bitrix\Main\DB\SqlExpression $isDepartment)
	 * @method bool hasIsDepartment()
	 * @method bool isIsDepartmentFilled()
	 * @method bool isIsDepartmentChanged()
	 * @method \boolean remindActualIsDepartment()
	 * @method \boolean requireIsDepartment()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation resetIsDepartment()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation unsetIsDepartment()
	 * @method \boolean fillIsDepartment()
	 * @method \boolean getIsIntegrator()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation setIsIntegrator(\boolean|\Bitrix\Main\DB\SqlExpression $isIntegrator)
	 * @method bool hasIsIntegrator()
	 * @method bool isIsIntegratorFilled()
	 * @method bool isIsIntegratorChanged()
	 * @method \boolean remindActualIsIntegrator()
	 * @method \boolean requireIsIntegrator()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation resetIsIntegrator()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation unsetIsIntegrator()
	 * @method \boolean fillIsIntegrator()
	 * @method \boolean getIsRegister()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation setIsRegister(\boolean|\Bitrix\Main\DB\SqlExpression $isRegister)
	 * @method bool hasIsRegister()
	 * @method bool isIsRegisterFilled()
	 * @method bool isIsRegisterChanged()
	 * @method \boolean remindActualIsRegister()
	 * @method \boolean requireIsRegister()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation resetIsRegister()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation unsetIsRegister()
	 * @method \boolean fillIsRegister()
	 * @method \Bitrix\Main\EO_User getUser()
	 * @method \Bitrix\Main\EO_User remindActualUser()
	 * @method \Bitrix\Main\EO_User requireUser()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation setUser(\Bitrix\Main\EO_User $object)
	 * @method \Bitrix\Intranet\Internals\EO_Invitation resetUser()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation unsetUser()
	 * @method bool hasUser()
	 * @method bool isUserFilled()
	 * @method bool isUserChanged()
	 * @method \Bitrix\Main\EO_User fillUser()
	 * @method \Bitrix\Main\EO_User getOriginator()
	 * @method \Bitrix\Main\EO_User remindActualOriginator()
	 * @method \Bitrix\Main\EO_User requireOriginator()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation setOriginator(\Bitrix\Main\EO_User $object)
	 * @method \Bitrix\Intranet\Internals\EO_Invitation resetOriginator()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation unsetOriginator()
	 * @method bool hasOriginator()
	 * @method bool isOriginatorFilled()
	 * @method bool isOriginatorChanged()
	 * @method \Bitrix\Main\EO_User fillOriginator()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @property-read array $primary
	 * @property-read int $state @see \Bitrix\Main\ORM\Objectify\State
	 * @property-read \Bitrix\Main\Type\Dictionary $customData
	 * @property \Bitrix\Main\Authentication\Context $authContext
	 * @method mixed get($fieldName)
	 * @method mixed remindActual($fieldName)
	 * @method mixed require($fieldName)
	 * @method bool has($fieldName)
	 * @method bool isFilled($fieldName)
	 * @method bool isChanged($fieldName)
	 * @method \Bitrix\Intranet\Internals\EO_Invitation set($fieldName, $value)
	 * @method \Bitrix\Intranet\Internals\EO_Invitation reset($fieldName)
	 * @method \Bitrix\Intranet\Internals\EO_Invitation unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Intranet\Internals\EO_Invitation wakeUp($data)
	 */
	class EO_Invitation {
		/* @var \Bitrix\Intranet\Internals\InvitationTable */
		static public $dataClass = '\Bitrix\Intranet\Internals\InvitationTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Intranet\Internals {
	/**
	 * EO_Invitation_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \int[] getOriginatorIdList()
	 * @method \int[] fillOriginatorId()
	 * @method \string[] getInvitationTypeList()
	 * @method \string[] fillInvitationType()
	 * @method \Bitrix\Main\Type\DateTime[] getDateCreateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillDateCreate()
	 * @method \boolean[] getInitializedList()
	 * @method \boolean[] fillInitialized()
	 * @method \boolean[] getIsMassList()
	 * @method \boolean[] fillIsMass()
	 * @method \boolean[] getIsDepartmentList()
	 * @method \boolean[] fillIsDepartment()
	 * @method \boolean[] getIsIntegratorList()
	 * @method \boolean[] fillIsIntegrator()
	 * @method \boolean[] getIsRegisterList()
	 * @method \boolean[] fillIsRegister()
	 * @method \Bitrix\Main\EO_User[] getUserList()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation_Collection getUserCollection()
	 * @method \Bitrix\Main\EO_User_Collection fillUser()
	 * @method \Bitrix\Main\EO_User[] getOriginatorList()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation_Collection getOriginatorCollection()
	 * @method \Bitrix\Main\EO_User_Collection fillOriginator()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Intranet\Internals\EO_Invitation $object)
	 * @method bool has(\Bitrix\Intranet\Internals\EO_Invitation $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Intranet\Internals\EO_Invitation getByPrimary($primary)
	 * @method \Bitrix\Intranet\Internals\EO_Invitation[] getAll()
	 * @method bool remove(\Bitrix\Intranet\Internals\EO_Invitation $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Intranet\Internals\EO_Invitation_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Intranet\Internals\EO_Invitation current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Intranet\Internals\EO_Invitation_Collection merge(?\Bitrix\Intranet\Internals\EO_Invitation_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 */
	class EO_Invitation_Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Intranet\Internals\InvitationTable */
		static public $dataClass = '\Bitrix\Intranet\Internals\InvitationTable';
	}
}
namespace Bitrix\Intranet\Internals {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Invitation_Result exec()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation fetchObject()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation_Collection fetchCollection()
	 */
	class EO_Invitation_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Intranet\Internals\EO_Invitation fetchObject()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation_Collection fetchCollection()
	 */
	class EO_Invitation_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Intranet\Internals\EO_Invitation createObject($setDefaultValues = true)
	 * @method \Bitrix\Intranet\Internals\EO_Invitation_Collection createCollection()
	 * @method \Bitrix\Intranet\Internals\EO_Invitation wakeUpObject($row)
	 * @method \Bitrix\Intranet\Internals\EO_Invitation_Collection wakeUpCollection($rows)
	 */
	class EO_Invitation_Entity extends \Bitrix\Main\ORM\Entity {}
}