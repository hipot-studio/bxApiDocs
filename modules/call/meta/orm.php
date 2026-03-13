<?php

/* ORMENTITYANNOTATION:Bitrix\Call\Model\CallTrackTable:call/lib/model/calltracktable.php */
namespace Bitrix\Call\Model {
	/**
	 * Track
	 * @see \Bitrix\Call\Model\CallTrackTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Call\Track setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getCallId()
	 * @method \Bitrix\Call\Track setCallId(\int|\Bitrix\Main\DB\SqlExpression $callId)
	 * @method bool hasCallId()
	 * @method bool isCallIdFilled()
	 * @method bool isCallIdChanged()
	 * @method \int remindActualCallId()
	 * @method \int requireCallId()
	 * @method \Bitrix\Call\Track resetCallId()
	 * @method \Bitrix\Call\Track unsetCallId()
	 * @method \int fillCallId()
	 * @method null|\int getExternalTrackId()
	 * @method \Bitrix\Call\Track setExternalTrackId(null|\int|\Bitrix\Main\DB\SqlExpression $externalTrackId)
	 * @method bool hasExternalTrackId()
	 * @method bool isExternalTrackIdFilled()
	 * @method bool isExternalTrackIdChanged()
	 * @method null|\int remindActualExternalTrackId()
	 * @method null|\int requireExternalTrackId()
	 * @method \Bitrix\Call\Track resetExternalTrackId()
	 * @method \Bitrix\Call\Track unsetExternalTrackId()
	 * @method null|\int fillExternalTrackId()
	 * @method null|\int getFileId()
	 * @method \Bitrix\Call\Track setFileId(null|\int|\Bitrix\Main\DB\SqlExpression $fileId)
	 * @method bool hasFileId()
	 * @method bool isFileIdFilled()
	 * @method bool isFileIdChanged()
	 * @method null|\int remindActualFileId()
	 * @method null|\int requireFileId()
	 * @method \Bitrix\Call\Track resetFileId()
	 * @method \Bitrix\Call\Track unsetFileId()
	 * @method null|\int fillFileId()
	 * @method null|\int getDiskFileId()
	 * @method \Bitrix\Call\Track setDiskFileId(null|\int|\Bitrix\Main\DB\SqlExpression $diskFileId)
	 * @method bool hasDiskFileId()
	 * @method bool isDiskFileIdFilled()
	 * @method bool isDiskFileIdChanged()
	 * @method null|\int remindActualDiskFileId()
	 * @method null|\int requireDiskFileId()
	 * @method \Bitrix\Call\Track resetDiskFileId()
	 * @method \Bitrix\Call\Track unsetDiskFileId()
	 * @method null|\int fillDiskFileId()
	 * @method \Bitrix\Main\Type\DateTime getDateCreate()
	 * @method \Bitrix\Call\Track setDateCreate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateCreate)
	 * @method bool hasDateCreate()
	 * @method bool isDateCreateFilled()
	 * @method bool isDateCreateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualDateCreate()
	 * @method \Bitrix\Main\Type\DateTime requireDateCreate()
	 * @method \Bitrix\Call\Track resetDateCreate()
	 * @method \Bitrix\Call\Track unsetDateCreate()
	 * @method \Bitrix\Main\Type\DateTime fillDateCreate()
	 * @method null|\string getType()
	 * @method \Bitrix\Call\Track setType(null|\string|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method null|\string remindActualType()
	 * @method null|\string requireType()
	 * @method \Bitrix\Call\Track resetType()
	 * @method \Bitrix\Call\Track unsetType()
	 * @method null|\string fillType()
	 * @method null|\string getDownloadUrl()
	 * @method \Bitrix\Call\Track setDownloadUrl(null|\string|\Bitrix\Main\DB\SqlExpression $downloadUrl)
	 * @method bool hasDownloadUrl()
	 * @method bool isDownloadUrlFilled()
	 * @method bool isDownloadUrlChanged()
	 * @method null|\string remindActualDownloadUrl()
	 * @method null|\string requireDownloadUrl()
	 * @method \Bitrix\Call\Track resetDownloadUrl()
	 * @method \Bitrix\Call\Track unsetDownloadUrl()
	 * @method null|\string fillDownloadUrl()
	 * @method null|\string getFileName()
	 * @method \Bitrix\Call\Track setFileName(null|\string|\Bitrix\Main\DB\SqlExpression $fileName)
	 * @method bool hasFileName()
	 * @method bool isFileNameFilled()
	 * @method bool isFileNameChanged()
	 * @method null|\string remindActualFileName()
	 * @method null|\string requireFileName()
	 * @method \Bitrix\Call\Track resetFileName()
	 * @method \Bitrix\Call\Track unsetFileName()
	 * @method null|\string fillFileName()
	 * @method null|\int getDuration()
	 * @method \Bitrix\Call\Track setDuration(null|\int|\Bitrix\Main\DB\SqlExpression $duration)
	 * @method bool hasDuration()
	 * @method bool isDurationFilled()
	 * @method bool isDurationChanged()
	 * @method null|\int remindActualDuration()
	 * @method null|\int requireDuration()
	 * @method \Bitrix\Call\Track resetDuration()
	 * @method \Bitrix\Call\Track unsetDuration()
	 * @method null|\int fillDuration()
	 * @method null|\int getFileSize()
	 * @method \Bitrix\Call\Track setFileSize(null|\int|\Bitrix\Main\DB\SqlExpression $fileSize)
	 * @method bool hasFileSize()
	 * @method bool isFileSizeFilled()
	 * @method bool isFileSizeChanged()
	 * @method null|\int remindActualFileSize()
	 * @method null|\int requireFileSize()
	 * @method \Bitrix\Call\Track resetFileSize()
	 * @method \Bitrix\Call\Track unsetFileSize()
	 * @method null|\int fillFileSize()
	 * @method null|\string getFileMimeType()
	 * @method \Bitrix\Call\Track setFileMimeType(null|\string|\Bitrix\Main\DB\SqlExpression $fileMimeType)
	 * @method bool hasFileMimeType()
	 * @method bool isFileMimeTypeFilled()
	 * @method bool isFileMimeTypeChanged()
	 * @method null|\string remindActualFileMimeType()
	 * @method null|\string requireFileMimeType()
	 * @method \Bitrix\Call\Track resetFileMimeType()
	 * @method \Bitrix\Call\Track unsetFileMimeType()
	 * @method null|\string fillFileMimeType()
	 * @method null|\string getTempPath()
	 * @method \Bitrix\Call\Track setTempPath(null|\string|\Bitrix\Main\DB\SqlExpression $tempPath)
	 * @method bool hasTempPath()
	 * @method bool isTempPathFilled()
	 * @method bool isTempPathChanged()
	 * @method null|\string remindActualTempPath()
	 * @method null|\string requireTempPath()
	 * @method \Bitrix\Call\Track resetTempPath()
	 * @method \Bitrix\Call\Track unsetTempPath()
	 * @method null|\string fillTempPath()
	 * @method \boolean getDownloaded()
	 * @method \Bitrix\Call\Track setDownloaded(\boolean|\Bitrix\Main\DB\SqlExpression $downloaded)
	 * @method bool hasDownloaded()
	 * @method bool isDownloadedFilled()
	 * @method bool isDownloadedChanged()
	 * @method \boolean remindActualDownloaded()
	 * @method \boolean requireDownloaded()
	 * @method \Bitrix\Call\Track resetDownloaded()
	 * @method \Bitrix\Call\Track unsetDownloaded()
	 * @method \boolean fillDownloaded()
	 * @method \Bitrix\Call\Model\EO_Call getCall()
	 * @method \Bitrix\Call\Model\EO_Call remindActualCall()
	 * @method \Bitrix\Call\Model\EO_Call requireCall()
	 * @method \Bitrix\Call\Track setCall(\Bitrix\Call\Model\EO_Call $object)
	 * @method \Bitrix\Call\Track resetCall()
	 * @method \Bitrix\Call\Track unsetCall()
	 * @method bool hasCall()
	 * @method bool isCallFilled()
	 * @method bool isCallChanged()
	 * @method \Bitrix\Call\Model\EO_Call fillCall()
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
	 * @method \Bitrix\Call\Track set($fieldName, $value)
	 * @method \Bitrix\Call\Track reset($fieldName)
	 * @method \Bitrix\Call\Track unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Call\Track wakeUp($data)
	 */
	class EO_CallTrack extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Call\Model\CallTrackTable */
		static public $dataClass = '\Bitrix\Call\Model\CallTrackTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Call\Model {
	/**
	 * TrackCollection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getCallIdList()
	 * @method \int[] fillCallId()
	 * @method null|\int[] getExternalTrackIdList()
	 * @method null|\int[] fillExternalTrackId()
	 * @method null|\int[] getFileIdList()
	 * @method null|\int[] fillFileId()
	 * @method null|\int[] getDiskFileIdList()
	 * @method null|\int[] fillDiskFileId()
	 * @method \Bitrix\Main\Type\DateTime[] getDateCreateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillDateCreate()
	 * @method null|\string[] getTypeList()
	 * @method null|\string[] fillType()
	 * @method null|\string[] getDownloadUrlList()
	 * @method null|\string[] fillDownloadUrl()
	 * @method null|\string[] getFileNameList()
	 * @method null|\string[] fillFileName()
	 * @method null|\int[] getDurationList()
	 * @method null|\int[] fillDuration()
	 * @method null|\int[] getFileSizeList()
	 * @method null|\int[] fillFileSize()
	 * @method null|\string[] getFileMimeTypeList()
	 * @method null|\string[] fillFileMimeType()
	 * @method null|\string[] getTempPathList()
	 * @method null|\string[] fillTempPath()
	 * @method \boolean[] getDownloadedList()
	 * @method \boolean[] fillDownloaded()
	 * @method \Bitrix\Call\Model\EO_Call[] getCallList()
	 * @method \Bitrix\Call\Track\TrackCollection getCallCollection()
	 * @method \Bitrix\Call\Model\EO_Call_Collection fillCall()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Call\Track $object)
	 * @method bool has(\Bitrix\Call\Track $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Call\Track getByPrimary($primary)
	 * @method \Bitrix\Call\Track[] getAll()
	 * @method bool remove(\Bitrix\Call\Track $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Call\Track\TrackCollection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Call\Track current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Call\Track\TrackCollection merge(?\Bitrix\Call\Track\TrackCollection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Call\Track|null find(callable $callback)
	 * @method \Bitrix\Call\Track\TrackCollection filter(callable $callback)
	 */
	class EO_CallTrack_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Call\Model\CallTrackTable */
		static public $dataClass = '\Bitrix\Call\Model\CallTrackTable';
	}
}
namespace Bitrix\Call\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_CallTrack_Result exec()
	 * @method \Bitrix\Call\Track fetchObject()
	 * @method \Bitrix\Call\Track\TrackCollection fetchCollection()
	 */
	class EO_CallTrack_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Call\Track fetchObject()
	 * @method \Bitrix\Call\Track\TrackCollection fetchCollection()
	 */
	class EO_CallTrack_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Call\Track createObject($setDefaultValues = true)
	 * @method \Bitrix\Call\Track\TrackCollection createCollection()
	 * @method \Bitrix\Call\Track wakeUpObject($row)
	 * @method \Bitrix\Call\Track\TrackCollection wakeUpCollection($rows)
	 */
	class EO_CallTrack_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Call\Model\CallChatEntityTable:call/lib/model/callchatentitytable.php */
namespace Bitrix\Call\Model {
	/**
	 * CallChatEntity
	 * @see \Bitrix\Call\Model\CallChatEntityTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Call\CallChatEntity setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getChatId()
	 * @method \Bitrix\Call\CallChatEntity setChatId(\int|\Bitrix\Main\DB\SqlExpression $chatId)
	 * @method bool hasChatId()
	 * @method bool isChatIdFilled()
	 * @method bool isChatIdChanged()
	 * @method \int remindActualChatId()
	 * @method \int requireChatId()
	 * @method \Bitrix\Call\CallChatEntity resetChatId()
	 * @method \Bitrix\Call\CallChatEntity unsetChatId()
	 * @method \int fillChatId()
	 * @method \int getCallTokenVersion()
	 * @method \Bitrix\Call\CallChatEntity setCallTokenVersion(\int|\Bitrix\Main\DB\SqlExpression $callTokenVersion)
	 * @method bool hasCallTokenVersion()
	 * @method bool isCallTokenVersionFilled()
	 * @method bool isCallTokenVersionChanged()
	 * @method \int remindActualCallTokenVersion()
	 * @method \int requireCallTokenVersion()
	 * @method \Bitrix\Call\CallChatEntity resetCallTokenVersion()
	 * @method \Bitrix\Call\CallChatEntity unsetCallTokenVersion()
	 * @method \int fillCallTokenVersion()
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
	 * @method \Bitrix\Call\CallChatEntity set($fieldName, $value)
	 * @method \Bitrix\Call\CallChatEntity reset($fieldName)
	 * @method \Bitrix\Call\CallChatEntity unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Call\CallChatEntity wakeUp($data)
	 */
	class EO_CallChatEntity extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Call\Model\CallChatEntityTable */
		static public $dataClass = '\Bitrix\Call\Model\CallChatEntityTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Call\Model {
	/**
	 * EO_CallChatEntity_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getChatIdList()
	 * @method \int[] fillChatId()
	 * @method \int[] getCallTokenVersionList()
	 * @method \int[] fillCallTokenVersion()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Call\CallChatEntity $object)
	 * @method bool has(\Bitrix\Call\CallChatEntity $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Call\CallChatEntity getByPrimary($primary)
	 * @method \Bitrix\Call\CallChatEntity[] getAll()
	 * @method bool remove(\Bitrix\Call\CallChatEntity $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Call\Model\EO_CallChatEntity_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Call\CallChatEntity current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Call\Model\EO_CallChatEntity_Collection merge(?\Bitrix\Call\Model\EO_CallChatEntity_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Call\CallChatEntity|null find(callable $callback)
	 * @method \Bitrix\Call\Model\EO_CallChatEntity_Collection filter(callable $callback)
	 */
	class EO_CallChatEntity_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Call\Model\CallChatEntityTable */
		static public $dataClass = '\Bitrix\Call\Model\CallChatEntityTable';
	}
}
namespace Bitrix\Call\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_CallChatEntity_Result exec()
	 * @method \Bitrix\Call\CallChatEntity fetchObject()
	 * @method \Bitrix\Call\Model\EO_CallChatEntity_Collection fetchCollection()
	 */
	class EO_CallChatEntity_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Call\CallChatEntity fetchObject()
	 * @method \Bitrix\Call\Model\EO_CallChatEntity_Collection fetchCollection()
	 */
	class EO_CallChatEntity_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Call\CallChatEntity createObject($setDefaultValues = true)
	 * @method \Bitrix\Call\Model\EO_CallChatEntity_Collection createCollection()
	 * @method \Bitrix\Call\CallChatEntity wakeUpObject($row)
	 * @method \Bitrix\Call\Model\EO_CallChatEntity_Collection wakeUpCollection($rows)
	 */
	class EO_CallChatEntity_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Call\Model\CallOutcomeTable:call/lib/model/calloutcometable.php */
namespace Bitrix\Call\Model {
	/**
	 * Outcome
	 * @see \Bitrix\Call\Model\CallOutcomeTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Call\Integration\AI\Outcome setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getCallId()
	 * @method \Bitrix\Call\Integration\AI\Outcome setCallId(\int|\Bitrix\Main\DB\SqlExpression $callId)
	 * @method bool hasCallId()
	 * @method bool isCallIdFilled()
	 * @method bool isCallIdChanged()
	 * @method \int remindActualCallId()
	 * @method \int requireCallId()
	 * @method \Bitrix\Call\Integration\AI\Outcome resetCallId()
	 * @method \Bitrix\Call\Integration\AI\Outcome unsetCallId()
	 * @method \int fillCallId()
	 * @method null|\int getTrackId()
	 * @method \Bitrix\Call\Integration\AI\Outcome setTrackId(null|\int|\Bitrix\Main\DB\SqlExpression $trackId)
	 * @method bool hasTrackId()
	 * @method bool isTrackIdFilled()
	 * @method bool isTrackIdChanged()
	 * @method null|\int remindActualTrackId()
	 * @method null|\int requireTrackId()
	 * @method \Bitrix\Call\Integration\AI\Outcome resetTrackId()
	 * @method \Bitrix\Call\Integration\AI\Outcome unsetTrackId()
	 * @method null|\int fillTrackId()
	 * @method null|\string getType()
	 * @method \Bitrix\Call\Integration\AI\Outcome setType(null|\string|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method null|\string remindActualType()
	 * @method null|\string requireType()
	 * @method \Bitrix\Call\Integration\AI\Outcome resetType()
	 * @method \Bitrix\Call\Integration\AI\Outcome unsetType()
	 * @method null|\string fillType()
	 * @method \Bitrix\Main\Type\DateTime getDateCreate()
	 * @method \Bitrix\Call\Integration\AI\Outcome setDateCreate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateCreate)
	 * @method bool hasDateCreate()
	 * @method bool isDateCreateFilled()
	 * @method bool isDateCreateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualDateCreate()
	 * @method \Bitrix\Main\Type\DateTime requireDateCreate()
	 * @method \Bitrix\Call\Integration\AI\Outcome resetDateCreate()
	 * @method \Bitrix\Call\Integration\AI\Outcome unsetDateCreate()
	 * @method \Bitrix\Main\Type\DateTime fillDateCreate()
	 * @method null|\string getLanguageId()
	 * @method \Bitrix\Call\Integration\AI\Outcome setLanguageId(null|\string|\Bitrix\Main\DB\SqlExpression $languageId)
	 * @method bool hasLanguageId()
	 * @method bool isLanguageIdFilled()
	 * @method bool isLanguageIdChanged()
	 * @method null|\string remindActualLanguageId()
	 * @method null|\string requireLanguageId()
	 * @method \Bitrix\Call\Integration\AI\Outcome resetLanguageId()
	 * @method \Bitrix\Call\Integration\AI\Outcome unsetLanguageId()
	 * @method null|\string fillLanguageId()
	 * @method null|\string getContent()
	 * @method \Bitrix\Call\Integration\AI\Outcome setContent(null|\string|\Bitrix\Main\DB\SqlExpression $content)
	 * @method bool hasContent()
	 * @method bool isContentFilled()
	 * @method bool isContentChanged()
	 * @method null|\string remindActualContent()
	 * @method null|\string requireContent()
	 * @method \Bitrix\Call\Integration\AI\Outcome resetContent()
	 * @method \Bitrix\Call\Integration\AI\Outcome unsetContent()
	 * @method null|\string fillContent()
	 * @method \Bitrix\Call\Track getTrack()
	 * @method \Bitrix\Call\Track remindActualTrack()
	 * @method \Bitrix\Call\Track requireTrack()
	 * @method \Bitrix\Call\Integration\AI\Outcome setTrack(\Bitrix\Call\Track $object)
	 * @method \Bitrix\Call\Integration\AI\Outcome resetTrack()
	 * @method \Bitrix\Call\Integration\AI\Outcome unsetTrack()
	 * @method bool hasTrack()
	 * @method bool isTrackFilled()
	 * @method bool isTrackChanged()
	 * @method \Bitrix\Call\Track fillTrack()
	 * @method \Bitrix\Call\Model\EO_Call getCall()
	 * @method \Bitrix\Call\Model\EO_Call remindActualCall()
	 * @method \Bitrix\Call\Model\EO_Call requireCall()
	 * @method \Bitrix\Call\Integration\AI\Outcome setCall(\Bitrix\Call\Model\EO_Call $object)
	 * @method \Bitrix\Call\Integration\AI\Outcome resetCall()
	 * @method \Bitrix\Call\Integration\AI\Outcome unsetCall()
	 * @method bool hasCall()
	 * @method bool isCallFilled()
	 * @method bool isCallChanged()
	 * @method \Bitrix\Call\Model\EO_Call fillCall()
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
	 * @method \Bitrix\Call\Integration\AI\Outcome set($fieldName, $value)
	 * @method \Bitrix\Call\Integration\AI\Outcome reset($fieldName)
	 * @method \Bitrix\Call\Integration\AI\Outcome unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Call\Integration\AI\Outcome wakeUp($data)
	 */
	class EO_CallOutcome extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Call\Model\CallOutcomeTable */
		static public $dataClass = '\Bitrix\Call\Model\CallOutcomeTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Call\Model {
	/**
	 * OutcomeCollection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getCallIdList()
	 * @method \int[] fillCallId()
	 * @method null|\int[] getTrackIdList()
	 * @method null|\int[] fillTrackId()
	 * @method null|\string[] getTypeList()
	 * @method null|\string[] fillType()
	 * @method \Bitrix\Main\Type\DateTime[] getDateCreateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillDateCreate()
	 * @method null|\string[] getLanguageIdList()
	 * @method null|\string[] fillLanguageId()
	 * @method null|\string[] getContentList()
	 * @method null|\string[] fillContent()
	 * @method \Bitrix\Call\Track[] getTrackList()
	 * @method \Bitrix\Call\Integration\AI\Outcome\OutcomeCollection getTrackCollection()
	 * @method \Bitrix\Call\Track\TrackCollection fillTrack()
	 * @method \Bitrix\Call\Model\EO_Call[] getCallList()
	 * @method \Bitrix\Call\Integration\AI\Outcome\OutcomeCollection getCallCollection()
	 * @method \Bitrix\Call\Model\EO_Call_Collection fillCall()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Call\Integration\AI\Outcome $object)
	 * @method bool has(\Bitrix\Call\Integration\AI\Outcome $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Call\Integration\AI\Outcome getByPrimary($primary)
	 * @method \Bitrix\Call\Integration\AI\Outcome[] getAll()
	 * @method bool remove(\Bitrix\Call\Integration\AI\Outcome $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Call\Integration\AI\Outcome\OutcomeCollection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Call\Integration\AI\Outcome current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Call\Integration\AI\Outcome\OutcomeCollection merge(?\Bitrix\Call\Integration\AI\Outcome\OutcomeCollection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Call\Integration\AI\Outcome|null find(callable $callback)
	 * @method \Bitrix\Call\Integration\AI\Outcome\OutcomeCollection filter(callable $callback)
	 */
	class EO_CallOutcome_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Call\Model\CallOutcomeTable */
		static public $dataClass = '\Bitrix\Call\Model\CallOutcomeTable';
	}
}
namespace Bitrix\Call\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_CallOutcome_Result exec()
	 * @method \Bitrix\Call\Integration\AI\Outcome fetchObject()
	 * @method \Bitrix\Call\Integration\AI\Outcome\OutcomeCollection fetchCollection()
	 */
	class EO_CallOutcome_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Call\Integration\AI\Outcome fetchObject()
	 * @method \Bitrix\Call\Integration\AI\Outcome\OutcomeCollection fetchCollection()
	 */
	class EO_CallOutcome_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Call\Integration\AI\Outcome createObject($setDefaultValues = true)
	 * @method \Bitrix\Call\Integration\AI\Outcome\OutcomeCollection createCollection()
	 * @method \Bitrix\Call\Integration\AI\Outcome wakeUpObject($row)
	 * @method \Bitrix\Call\Integration\AI\Outcome\OutcomeCollection wakeUpCollection($rows)
	 */
	class EO_CallOutcome_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Call\Model\CallOutcomePropertyTable:call/lib/model/calloutcomepropertytable.php */
namespace Bitrix\Call\Model {
	/**
	 * Property
	 * @see \Bitrix\Call\Model\CallOutcomePropertyTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getOutcomeId()
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property setOutcomeId(\int|\Bitrix\Main\DB\SqlExpression $outcomeId)
	 * @method bool hasOutcomeId()
	 * @method bool isOutcomeIdFilled()
	 * @method bool isOutcomeIdChanged()
	 * @method \int remindActualOutcomeId()
	 * @method \int requireOutcomeId()
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property resetOutcomeId()
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property unsetOutcomeId()
	 * @method \int fillOutcomeId()
	 * @method \string getCode()
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property setCode(\string|\Bitrix\Main\DB\SqlExpression $code)
	 * @method bool hasCode()
	 * @method bool isCodeFilled()
	 * @method bool isCodeChanged()
	 * @method \string remindActualCode()
	 * @method \string requireCode()
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property resetCode()
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property unsetCode()
	 * @method \string fillCode()
	 * @method null|\string getContent()
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property setContent(null|\string|\Bitrix\Main\DB\SqlExpression $content)
	 * @method bool hasContent()
	 * @method bool isContentFilled()
	 * @method bool isContentChanged()
	 * @method null|\string remindActualContent()
	 * @method null|\string requireContent()
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property resetContent()
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property unsetContent()
	 * @method null|\string fillContent()
	 * @method \Bitrix\Call\Integration\AI\Outcome getOutcome()
	 * @method \Bitrix\Call\Integration\AI\Outcome remindActualOutcome()
	 * @method \Bitrix\Call\Integration\AI\Outcome requireOutcome()
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property setOutcome(\Bitrix\Call\Integration\AI\Outcome $object)
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property resetOutcome()
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property unsetOutcome()
	 * @method bool hasOutcome()
	 * @method bool isOutcomeFilled()
	 * @method bool isOutcomeChanged()
	 * @method \Bitrix\Call\Integration\AI\Outcome fillOutcome()
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
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property set($fieldName, $value)
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property reset($fieldName)
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Call\Integration\AI\Outcome\Property wakeUp($data)
	 */
	class EO_CallOutcomeProperty extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Call\Model\CallOutcomePropertyTable */
		static public $dataClass = '\Bitrix\Call\Model\CallOutcomePropertyTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Call\Model {
	/**
	 * EO_CallOutcomeProperty_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getOutcomeIdList()
	 * @method \int[] fillOutcomeId()
	 * @method \string[] getCodeList()
	 * @method \string[] fillCode()
	 * @method null|\string[] getContentList()
	 * @method null|\string[] fillContent()
	 * @method \Bitrix\Call\Integration\AI\Outcome[] getOutcomeList()
	 * @method \Bitrix\Call\Model\EO_CallOutcomeProperty_Collection getOutcomeCollection()
	 * @method \Bitrix\Call\Integration\AI\Outcome\OutcomeCollection fillOutcome()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Call\Integration\AI\Outcome\Property $object)
	 * @method bool has(\Bitrix\Call\Integration\AI\Outcome\Property $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property getByPrimary($primary)
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property[] getAll()
	 * @method bool remove(\Bitrix\Call\Integration\AI\Outcome\Property $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Call\Model\EO_CallOutcomeProperty_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Call\Model\EO_CallOutcomeProperty_Collection merge(?\Bitrix\Call\Model\EO_CallOutcomeProperty_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property|null find(callable $callback)
	 * @method \Bitrix\Call\Model\EO_CallOutcomeProperty_Collection filter(callable $callback)
	 */
	class EO_CallOutcomeProperty_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Call\Model\CallOutcomePropertyTable */
		static public $dataClass = '\Bitrix\Call\Model\CallOutcomePropertyTable';
	}
}
namespace Bitrix\Call\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_CallOutcomeProperty_Result exec()
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property fetchObject()
	 * @method \Bitrix\Call\Model\EO_CallOutcomeProperty_Collection fetchCollection()
	 */
	class EO_CallOutcomeProperty_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property fetchObject()
	 * @method \Bitrix\Call\Model\EO_CallOutcomeProperty_Collection fetchCollection()
	 */
	class EO_CallOutcomeProperty_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property createObject($setDefaultValues = true)
	 * @method \Bitrix\Call\Model\EO_CallOutcomeProperty_Collection createCollection()
	 * @method \Bitrix\Call\Integration\AI\Outcome\Property wakeUpObject($row)
	 * @method \Bitrix\Call\Model\EO_CallOutcomeProperty_Collection wakeUpCollection($rows)
	 */
	class EO_CallOutcomeProperty_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Call\Model\CallTable:call/lib/model/calltable.php */
namespace Bitrix\Call\Model {
	/**
	 * EO_Call
	 * @see \Bitrix\Call\Model\CallTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Call\Model\EO_Call setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getType()
	 * @method \Bitrix\Call\Model\EO_Call setType(\int|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method \int remindActualType()
	 * @method \int requireType()
	 * @method \Bitrix\Call\Model\EO_Call resetType()
	 * @method \Bitrix\Call\Model\EO_Call unsetType()
	 * @method \int fillType()
	 * @method null|\string getScheme()
	 * @method \Bitrix\Call\Model\EO_Call setScheme(null|\string|\Bitrix\Main\DB\SqlExpression $scheme)
	 * @method bool hasScheme()
	 * @method bool isSchemeFilled()
	 * @method bool isSchemeChanged()
	 * @method null|\string remindActualScheme()
	 * @method null|\string requireScheme()
	 * @method \Bitrix\Call\Model\EO_Call resetScheme()
	 * @method \Bitrix\Call\Model\EO_Call unsetScheme()
	 * @method null|\string fillScheme()
	 * @method \int getInitiatorId()
	 * @method \Bitrix\Call\Model\EO_Call setInitiatorId(\int|\Bitrix\Main\DB\SqlExpression $initiatorId)
	 * @method bool hasInitiatorId()
	 * @method bool isInitiatorIdFilled()
	 * @method bool isInitiatorIdChanged()
	 * @method \int remindActualInitiatorId()
	 * @method \int requireInitiatorId()
	 * @method \Bitrix\Call\Model\EO_Call resetInitiatorId()
	 * @method \Bitrix\Call\Model\EO_Call unsetInitiatorId()
	 * @method \int fillInitiatorId()
	 * @method \string getIsPublic()
	 * @method \Bitrix\Call\Model\EO_Call setIsPublic(\string|\Bitrix\Main\DB\SqlExpression $isPublic)
	 * @method bool hasIsPublic()
	 * @method bool isIsPublicFilled()
	 * @method bool isIsPublicChanged()
	 * @method \string remindActualIsPublic()
	 * @method \string requireIsPublic()
	 * @method \Bitrix\Call\Model\EO_Call resetIsPublic()
	 * @method \Bitrix\Call\Model\EO_Call unsetIsPublic()
	 * @method \string fillIsPublic()
	 * @method \string getPublicId()
	 * @method \Bitrix\Call\Model\EO_Call setPublicId(\string|\Bitrix\Main\DB\SqlExpression $publicId)
	 * @method bool hasPublicId()
	 * @method bool isPublicIdFilled()
	 * @method bool isPublicIdChanged()
	 * @method \string remindActualPublicId()
	 * @method \string requirePublicId()
	 * @method \Bitrix\Call\Model\EO_Call resetPublicId()
	 * @method \Bitrix\Call\Model\EO_Call unsetPublicId()
	 * @method \string fillPublicId()
	 * @method \string getProvider()
	 * @method \Bitrix\Call\Model\EO_Call setProvider(\string|\Bitrix\Main\DB\SqlExpression $provider)
	 * @method bool hasProvider()
	 * @method bool isProviderFilled()
	 * @method bool isProviderChanged()
	 * @method \string remindActualProvider()
	 * @method \string requireProvider()
	 * @method \Bitrix\Call\Model\EO_Call resetProvider()
	 * @method \Bitrix\Call\Model\EO_Call unsetProvider()
	 * @method \string fillProvider()
	 * @method \string getEntityType()
	 * @method \Bitrix\Call\Model\EO_Call setEntityType(\string|\Bitrix\Main\DB\SqlExpression $entityType)
	 * @method bool hasEntityType()
	 * @method bool isEntityTypeFilled()
	 * @method bool isEntityTypeChanged()
	 * @method \string remindActualEntityType()
	 * @method \string requireEntityType()
	 * @method \Bitrix\Call\Model\EO_Call resetEntityType()
	 * @method \Bitrix\Call\Model\EO_Call unsetEntityType()
	 * @method \string fillEntityType()
	 * @method \string getEntityId()
	 * @method \Bitrix\Call\Model\EO_Call setEntityId(\string|\Bitrix\Main\DB\SqlExpression $entityId)
	 * @method bool hasEntityId()
	 * @method bool isEntityIdFilled()
	 * @method bool isEntityIdChanged()
	 * @method \string remindActualEntityId()
	 * @method \string requireEntityId()
	 * @method \Bitrix\Call\Model\EO_Call resetEntityId()
	 * @method \Bitrix\Call\Model\EO_Call unsetEntityId()
	 * @method \string fillEntityId()
	 * @method \int getParentId()
	 * @method \Bitrix\Call\Model\EO_Call setParentId(\int|\Bitrix\Main\DB\SqlExpression $parentId)
	 * @method bool hasParentId()
	 * @method bool isParentIdFilled()
	 * @method bool isParentIdChanged()
	 * @method \int remindActualParentId()
	 * @method \int requireParentId()
	 * @method \Bitrix\Call\Model\EO_Call resetParentId()
	 * @method \Bitrix\Call\Model\EO_Call unsetParentId()
	 * @method \int fillParentId()
	 * @method \string getParentUuid()
	 * @method \Bitrix\Call\Model\EO_Call setParentUuid(\string|\Bitrix\Main\DB\SqlExpression $parentUuid)
	 * @method bool hasParentUuid()
	 * @method bool isParentUuidFilled()
	 * @method bool isParentUuidChanged()
	 * @method \string remindActualParentUuid()
	 * @method \string requireParentUuid()
	 * @method \Bitrix\Call\Model\EO_Call resetParentUuid()
	 * @method \Bitrix\Call\Model\EO_Call unsetParentUuid()
	 * @method \string fillParentUuid()
	 * @method \string getState()
	 * @method \Bitrix\Call\Model\EO_Call setState(\string|\Bitrix\Main\DB\SqlExpression $state)
	 * @method bool hasState()
	 * @method bool isStateFilled()
	 * @method bool isStateChanged()
	 * @method \string remindActualState()
	 * @method \string requireState()
	 * @method \Bitrix\Call\Model\EO_Call resetState()
	 * @method \Bitrix\Call\Model\EO_Call unsetState()
	 * @method \string fillState()
	 * @method \Bitrix\Main\Type\DateTime getStartDate()
	 * @method \Bitrix\Call\Model\EO_Call setStartDate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $startDate)
	 * @method bool hasStartDate()
	 * @method bool isStartDateFilled()
	 * @method bool isStartDateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualStartDate()
	 * @method \Bitrix\Main\Type\DateTime requireStartDate()
	 * @method \Bitrix\Call\Model\EO_Call resetStartDate()
	 * @method \Bitrix\Call\Model\EO_Call unsetStartDate()
	 * @method \Bitrix\Main\Type\DateTime fillStartDate()
	 * @method \Bitrix\Main\Type\DateTime getEndDate()
	 * @method \Bitrix\Call\Model\EO_Call setEndDate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $endDate)
	 * @method bool hasEndDate()
	 * @method bool isEndDateFilled()
	 * @method bool isEndDateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualEndDate()
	 * @method \Bitrix\Main\Type\DateTime requireEndDate()
	 * @method \Bitrix\Call\Model\EO_Call resetEndDate()
	 * @method \Bitrix\Call\Model\EO_Call unsetEndDate()
	 * @method \Bitrix\Main\Type\DateTime fillEndDate()
	 * @method \int getChatId()
	 * @method \Bitrix\Call\Model\EO_Call setChatId(\int|\Bitrix\Main\DB\SqlExpression $chatId)
	 * @method bool hasChatId()
	 * @method bool isChatIdFilled()
	 * @method bool isChatIdChanged()
	 * @method \int remindActualChatId()
	 * @method \int requireChatId()
	 * @method \Bitrix\Call\Model\EO_Call resetChatId()
	 * @method \Bitrix\Call\Model\EO_Call unsetChatId()
	 * @method \int fillChatId()
	 * @method \string getLogUrl()
	 * @method \Bitrix\Call\Model\EO_Call setLogUrl(\string|\Bitrix\Main\DB\SqlExpression $logUrl)
	 * @method bool hasLogUrl()
	 * @method bool isLogUrlFilled()
	 * @method bool isLogUrlChanged()
	 * @method \string remindActualLogUrl()
	 * @method \string requireLogUrl()
	 * @method \Bitrix\Call\Model\EO_Call resetLogUrl()
	 * @method \Bitrix\Call\Model\EO_Call unsetLogUrl()
	 * @method \string fillLogUrl()
	 * @method \string getUuid()
	 * @method \Bitrix\Call\Model\EO_Call setUuid(\string|\Bitrix\Main\DB\SqlExpression $uuid)
	 * @method bool hasUuid()
	 * @method bool isUuidFilled()
	 * @method bool isUuidChanged()
	 * @method \string remindActualUuid()
	 * @method \string requireUuid()
	 * @method \Bitrix\Call\Model\EO_Call resetUuid()
	 * @method \Bitrix\Call\Model\EO_Call unsetUuid()
	 * @method \string fillUuid()
	 * @method \string getSecretKey()
	 * @method \Bitrix\Call\Model\EO_Call setSecretKey(\string|\Bitrix\Main\DB\SqlExpression $secretKey)
	 * @method bool hasSecretKey()
	 * @method bool isSecretKeyFilled()
	 * @method bool isSecretKeyChanged()
	 * @method \string remindActualSecretKey()
	 * @method \string requireSecretKey()
	 * @method \Bitrix\Call\Model\EO_Call resetSecretKey()
	 * @method \Bitrix\Call\Model\EO_Call unsetSecretKey()
	 * @method \string fillSecretKey()
	 * @method \string getEndpoint()
	 * @method \Bitrix\Call\Model\EO_Call setEndpoint(\string|\Bitrix\Main\DB\SqlExpression $endpoint)
	 * @method bool hasEndpoint()
	 * @method bool isEndpointFilled()
	 * @method bool isEndpointChanged()
	 * @method \string remindActualEndpoint()
	 * @method \string requireEndpoint()
	 * @method \Bitrix\Call\Model\EO_Call resetEndpoint()
	 * @method \Bitrix\Call\Model\EO_Call unsetEndpoint()
	 * @method \string fillEndpoint()
	 * @method \boolean getRecordAudio()
	 * @method \Bitrix\Call\Model\EO_Call setRecordAudio(\boolean|\Bitrix\Main\DB\SqlExpression $recordAudio)
	 * @method bool hasRecordAudio()
	 * @method bool isRecordAudioFilled()
	 * @method bool isRecordAudioChanged()
	 * @method \boolean remindActualRecordAudio()
	 * @method \boolean requireRecordAudio()
	 * @method \Bitrix\Call\Model\EO_Call resetRecordAudio()
	 * @method \Bitrix\Call\Model\EO_Call unsetRecordAudio()
	 * @method \boolean fillRecordAudio()
	 * @method \boolean getAiAnalyze()
	 * @method \Bitrix\Call\Model\EO_Call setAiAnalyze(\boolean|\Bitrix\Main\DB\SqlExpression $aiAnalyze)
	 * @method bool hasAiAnalyze()
	 * @method bool isAiAnalyzeFilled()
	 * @method bool isAiAnalyzeChanged()
	 * @method \boolean remindActualAiAnalyze()
	 * @method \boolean requireAiAnalyze()
	 * @method \Bitrix\Call\Model\EO_Call resetAiAnalyze()
	 * @method \Bitrix\Call\Model\EO_Call unsetAiAnalyze()
	 * @method \boolean fillAiAnalyze()
	 * @method \Bitrix\Call\Model\EO_CallUser getCallUser()
	 * @method \Bitrix\Call\Model\EO_CallUser remindActualCallUser()
	 * @method \Bitrix\Call\Model\EO_CallUser requireCallUser()
	 * @method \Bitrix\Call\Model\EO_Call setCallUser(\Bitrix\Call\Model\EO_CallUser $object)
	 * @method \Bitrix\Call\Model\EO_Call resetCallUser()
	 * @method \Bitrix\Call\Model\EO_Call unsetCallUser()
	 * @method bool hasCallUser()
	 * @method bool isCallUserFilled()
	 * @method bool isCallUserChanged()
	 * @method \Bitrix\Call\Model\EO_CallUser fillCallUser()
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
	 * @method \Bitrix\Call\Model\EO_Call set($fieldName, $value)
	 * @method \Bitrix\Call\Model\EO_Call reset($fieldName)
	 * @method \Bitrix\Call\Model\EO_Call unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Call\Model\EO_Call wakeUp($data)
	 */
	class EO_Call extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Call\Model\CallTable */
		static public $dataClass = '\Bitrix\Call\Model\CallTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Call\Model {
	/**
	 * EO_Call_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getTypeList()
	 * @method \int[] fillType()
	 * @method null|\string[] getSchemeList()
	 * @method null|\string[] fillScheme()
	 * @method \int[] getInitiatorIdList()
	 * @method \int[] fillInitiatorId()
	 * @method \string[] getIsPublicList()
	 * @method \string[] fillIsPublic()
	 * @method \string[] getPublicIdList()
	 * @method \string[] fillPublicId()
	 * @method \string[] getProviderList()
	 * @method \string[] fillProvider()
	 * @method \string[] getEntityTypeList()
	 * @method \string[] fillEntityType()
	 * @method \string[] getEntityIdList()
	 * @method \string[] fillEntityId()
	 * @method \int[] getParentIdList()
	 * @method \int[] fillParentId()
	 * @method \string[] getParentUuidList()
	 * @method \string[] fillParentUuid()
	 * @method \string[] getStateList()
	 * @method \string[] fillState()
	 * @method \Bitrix\Main\Type\DateTime[] getStartDateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillStartDate()
	 * @method \Bitrix\Main\Type\DateTime[] getEndDateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillEndDate()
	 * @method \int[] getChatIdList()
	 * @method \int[] fillChatId()
	 * @method \string[] getLogUrlList()
	 * @method \string[] fillLogUrl()
	 * @method \string[] getUuidList()
	 * @method \string[] fillUuid()
	 * @method \string[] getSecretKeyList()
	 * @method \string[] fillSecretKey()
	 * @method \string[] getEndpointList()
	 * @method \string[] fillEndpoint()
	 * @method \boolean[] getRecordAudioList()
	 * @method \boolean[] fillRecordAudio()
	 * @method \boolean[] getAiAnalyzeList()
	 * @method \boolean[] fillAiAnalyze()
	 * @method \Bitrix\Call\Model\EO_CallUser[] getCallUserList()
	 * @method \Bitrix\Call\Model\EO_Call_Collection getCallUserCollection()
	 * @method \Bitrix\Call\Model\EO_CallUser_Collection fillCallUser()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Call\Model\EO_Call $object)
	 * @method bool has(\Bitrix\Call\Model\EO_Call $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Call\Model\EO_Call getByPrimary($primary)
	 * @method \Bitrix\Call\Model\EO_Call[] getAll()
	 * @method bool remove(\Bitrix\Call\Model\EO_Call $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Call\Model\EO_Call_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Call\Model\EO_Call current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Call\Model\EO_Call_Collection merge(?\Bitrix\Call\Model\EO_Call_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Call\Model\EO_Call|null find(callable $callback)
	 * @method \Bitrix\Call\Model\EO_Call_Collection filter(callable $callback)
	 */
	class EO_Call_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Call\Model\CallTable */
		static public $dataClass = '\Bitrix\Call\Model\CallTable';
	}
}
namespace Bitrix\Call\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Call_Result exec()
	 * @method \Bitrix\Call\Model\EO_Call fetchObject()
	 * @method \Bitrix\Call\Model\EO_Call_Collection fetchCollection()
	 */
	class EO_Call_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Call\Model\EO_Call fetchObject()
	 * @method \Bitrix\Call\Model\EO_Call_Collection fetchCollection()
	 */
	class EO_Call_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Call\Model\EO_Call createObject($setDefaultValues = true)
	 * @method \Bitrix\Call\Model\EO_Call_Collection createCollection()
	 * @method \Bitrix\Call\Model\EO_Call wakeUpObject($row)
	 * @method \Bitrix\Call\Model\EO_Call_Collection wakeUpCollection($rows)
	 */
	class EO_Call_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Call\Model\CallUserTable:call/lib/model/callusertable.php */
namespace Bitrix\Call\Model {
	/**
	 * EO_CallUser
	 * @see \Bitrix\Call\Model\CallUserTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getCallId()
	 * @method \Bitrix\Call\Model\EO_CallUser setCallId(\int|\Bitrix\Main\DB\SqlExpression $callId)
	 * @method bool hasCallId()
	 * @method bool isCallIdFilled()
	 * @method bool isCallIdChanged()
	 * @method \int getUserId()
	 * @method \Bitrix\Call\Model\EO_CallUser setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \string getState()
	 * @method \Bitrix\Call\Model\EO_CallUser setState(\string|\Bitrix\Main\DB\SqlExpression $state)
	 * @method bool hasState()
	 * @method bool isStateFilled()
	 * @method bool isStateChanged()
	 * @method \string remindActualState()
	 * @method \string requireState()
	 * @method \Bitrix\Call\Model\EO_CallUser resetState()
	 * @method \Bitrix\Call\Model\EO_CallUser unsetState()
	 * @method \string fillState()
	 * @method \Bitrix\Main\Type\DateTime getFirstJoined()
	 * @method \Bitrix\Call\Model\EO_CallUser setFirstJoined(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $firstJoined)
	 * @method bool hasFirstJoined()
	 * @method bool isFirstJoinedFilled()
	 * @method bool isFirstJoinedChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualFirstJoined()
	 * @method \Bitrix\Main\Type\DateTime requireFirstJoined()
	 * @method \Bitrix\Call\Model\EO_CallUser resetFirstJoined()
	 * @method \Bitrix\Call\Model\EO_CallUser unsetFirstJoined()
	 * @method \Bitrix\Main\Type\DateTime fillFirstJoined()
	 * @method \Bitrix\Main\Type\DateTime getLastSeen()
	 * @method \Bitrix\Call\Model\EO_CallUser setLastSeen(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $lastSeen)
	 * @method bool hasLastSeen()
	 * @method bool isLastSeenFilled()
	 * @method bool isLastSeenChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualLastSeen()
	 * @method \Bitrix\Main\Type\DateTime requireLastSeen()
	 * @method \Bitrix\Call\Model\EO_CallUser resetLastSeen()
	 * @method \Bitrix\Call\Model\EO_CallUser unsetLastSeen()
	 * @method \Bitrix\Main\Type\DateTime fillLastSeen()
	 * @method \boolean getIsMobile()
	 * @method \Bitrix\Call\Model\EO_CallUser setIsMobile(\boolean|\Bitrix\Main\DB\SqlExpression $isMobile)
	 * @method bool hasIsMobile()
	 * @method bool isIsMobileFilled()
	 * @method bool isIsMobileChanged()
	 * @method \boolean remindActualIsMobile()
	 * @method \boolean requireIsMobile()
	 * @method \Bitrix\Call\Model\EO_CallUser resetIsMobile()
	 * @method \Bitrix\Call\Model\EO_CallUser unsetIsMobile()
	 * @method \boolean fillIsMobile()
	 * @method \boolean getSharedScreen()
	 * @method \Bitrix\Call\Model\EO_CallUser setSharedScreen(\boolean|\Bitrix\Main\DB\SqlExpression $sharedScreen)
	 * @method bool hasSharedScreen()
	 * @method bool isSharedScreenFilled()
	 * @method bool isSharedScreenChanged()
	 * @method \boolean remindActualSharedScreen()
	 * @method \boolean requireSharedScreen()
	 * @method \Bitrix\Call\Model\EO_CallUser resetSharedScreen()
	 * @method \Bitrix\Call\Model\EO_CallUser unsetSharedScreen()
	 * @method \boolean fillSharedScreen()
	 * @method \boolean getRecorded()
	 * @method \Bitrix\Call\Model\EO_CallUser setRecorded(\boolean|\Bitrix\Main\DB\SqlExpression $recorded)
	 * @method bool hasRecorded()
	 * @method bool isRecordedFilled()
	 * @method bool isRecordedChanged()
	 * @method \boolean remindActualRecorded()
	 * @method \boolean requireRecorded()
	 * @method \Bitrix\Call\Model\EO_CallUser resetRecorded()
	 * @method \Bitrix\Call\Model\EO_CallUser unsetRecorded()
	 * @method \boolean fillRecorded()
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
	 * @method \Bitrix\Call\Model\EO_CallUser set($fieldName, $value)
	 * @method \Bitrix\Call\Model\EO_CallUser reset($fieldName)
	 * @method \Bitrix\Call\Model\EO_CallUser unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Call\Model\EO_CallUser wakeUp($data)
	 */
	class EO_CallUser extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Call\Model\CallUserTable */
		static public $dataClass = '\Bitrix\Call\Model\CallUserTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Call\Model {
	/**
	 * EO_CallUser_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getCallIdList()
	 * @method \int[] getUserIdList()
	 * @method \string[] getStateList()
	 * @method \string[] fillState()
	 * @method \Bitrix\Main\Type\DateTime[] getFirstJoinedList()
	 * @method \Bitrix\Main\Type\DateTime[] fillFirstJoined()
	 * @method \Bitrix\Main\Type\DateTime[] getLastSeenList()
	 * @method \Bitrix\Main\Type\DateTime[] fillLastSeen()
	 * @method \boolean[] getIsMobileList()
	 * @method \boolean[] fillIsMobile()
	 * @method \boolean[] getSharedScreenList()
	 * @method \boolean[] fillSharedScreen()
	 * @method \boolean[] getRecordedList()
	 * @method \boolean[] fillRecorded()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Call\Model\EO_CallUser $object)
	 * @method bool has(\Bitrix\Call\Model\EO_CallUser $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Call\Model\EO_CallUser getByPrimary($primary)
	 * @method \Bitrix\Call\Model\EO_CallUser[] getAll()
	 * @method bool remove(\Bitrix\Call\Model\EO_CallUser $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Call\Model\EO_CallUser_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Call\Model\EO_CallUser current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Call\Model\EO_CallUser_Collection merge(?\Bitrix\Call\Model\EO_CallUser_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Call\Model\EO_CallUser|null find(callable $callback)
	 * @method \Bitrix\Call\Model\EO_CallUser_Collection filter(callable $callback)
	 */
	class EO_CallUser_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Call\Model\CallUserTable */
		static public $dataClass = '\Bitrix\Call\Model\CallUserTable';
	}
}
namespace Bitrix\Call\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_CallUser_Result exec()
	 * @method \Bitrix\Call\Model\EO_CallUser fetchObject()
	 * @method \Bitrix\Call\Model\EO_CallUser_Collection fetchCollection()
	 */
	class EO_CallUser_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Call\Model\EO_CallUser fetchObject()
	 * @method \Bitrix\Call\Model\EO_CallUser_Collection fetchCollection()
	 */
	class EO_CallUser_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Call\Model\EO_CallUser createObject($setDefaultValues = true)
	 * @method \Bitrix\Call\Model\EO_CallUser_Collection createCollection()
	 * @method \Bitrix\Call\Model\EO_CallUser wakeUpObject($row)
	 * @method \Bitrix\Call\Model\EO_CallUser_Collection wakeUpCollection($rows)
	 */
	class EO_CallUser_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Call\Model\CallUserLogTable:call/lib/model/calluserlogtable.php */
namespace Bitrix\Call\Model {
	/**
	 * EO_CallUserLog
	 * @see \Bitrix\Call\Model\CallUserLogTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Call\Model\EO_CallUserLog setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \string getSourceType()
	 * @method \Bitrix\Call\Model\EO_CallUserLog setSourceType(\string|\Bitrix\Main\DB\SqlExpression $sourceType)
	 * @method bool hasSourceType()
	 * @method bool isSourceTypeFilled()
	 * @method bool isSourceTypeChanged()
	 * @method \string remindActualSourceType()
	 * @method \string requireSourceType()
	 * @method \Bitrix\Call\Model\EO_CallUserLog resetSourceType()
	 * @method \Bitrix\Call\Model\EO_CallUserLog unsetSourceType()
	 * @method \string fillSourceType()
	 * @method \int getSourceCallId()
	 * @method \Bitrix\Call\Model\EO_CallUserLog setSourceCallId(\int|\Bitrix\Main\DB\SqlExpression $sourceCallId)
	 * @method bool hasSourceCallId()
	 * @method bool isSourceCallIdFilled()
	 * @method bool isSourceCallIdChanged()
	 * @method \int remindActualSourceCallId()
	 * @method \int requireSourceCallId()
	 * @method \Bitrix\Call\Model\EO_CallUserLog resetSourceCallId()
	 * @method \Bitrix\Call\Model\EO_CallUserLog unsetSourceCallId()
	 * @method \int fillSourceCallId()
	 * @method \int getUserId()
	 * @method \Bitrix\Call\Model\EO_CallUserLog setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\Call\Model\EO_CallUserLog resetUserId()
	 * @method \Bitrix\Call\Model\EO_CallUserLog unsetUserId()
	 * @method \int fillUserId()
	 * @method \string getStatus()
	 * @method \Bitrix\Call\Model\EO_CallUserLog setStatus(\string|\Bitrix\Main\DB\SqlExpression $status)
	 * @method bool hasStatus()
	 * @method bool isStatusFilled()
	 * @method bool isStatusChanged()
	 * @method \string remindActualStatus()
	 * @method \string requireStatus()
	 * @method \Bitrix\Call\Model\EO_CallUserLog resetStatus()
	 * @method \Bitrix\Call\Model\EO_CallUserLog unsetStatus()
	 * @method \string fillStatus()
	 * @method \Bitrix\Main\Type\DateTime getStatusTime()
	 * @method \Bitrix\Call\Model\EO_CallUserLog setStatusTime(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $statusTime)
	 * @method bool hasStatusTime()
	 * @method bool isStatusTimeFilled()
	 * @method bool isStatusTimeChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualStatusTime()
	 * @method \Bitrix\Main\Type\DateTime requireStatusTime()
	 * @method \Bitrix\Call\Model\EO_CallUserLog resetStatusTime()
	 * @method \Bitrix\Call\Model\EO_CallUserLog unsetStatusTime()
	 * @method \Bitrix\Main\Type\DateTime fillStatusTime()
	 * @method \Bitrix\Main\EO_User getUser()
	 * @method \Bitrix\Main\EO_User remindActualUser()
	 * @method \Bitrix\Main\EO_User requireUser()
	 * @method \Bitrix\Call\Model\EO_CallUserLog setUser(\Bitrix\Main\EO_User $object)
	 * @method \Bitrix\Call\Model\EO_CallUserLog resetUser()
	 * @method \Bitrix\Call\Model\EO_CallUserLog unsetUser()
	 * @method bool hasUser()
	 * @method bool isUserFilled()
	 * @method bool isUserChanged()
	 * @method \Bitrix\Main\EO_User fillUser()
	 * @method \Bitrix\Voximplant\EO_Statistic getVoximplantStat()
	 * @method \Bitrix\Voximplant\EO_Statistic remindActualVoximplantStat()
	 * @method \Bitrix\Voximplant\EO_Statistic requireVoximplantStat()
	 * @method \Bitrix\Call\Model\EO_CallUserLog setVoximplantStat(\Bitrix\Voximplant\EO_Statistic $object)
	 * @method \Bitrix\Call\Model\EO_CallUserLog resetVoximplantStat()
	 * @method \Bitrix\Call\Model\EO_CallUserLog unsetVoximplantStat()
	 * @method bool hasVoximplantStat()
	 * @method bool isVoximplantStatFilled()
	 * @method bool isVoximplantStatChanged()
	 * @method \Bitrix\Voximplant\EO_Statistic fillVoximplantStat()
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
	 * @method \Bitrix\Call\Model\EO_CallUserLog set($fieldName, $value)
	 * @method \Bitrix\Call\Model\EO_CallUserLog reset($fieldName)
	 * @method \Bitrix\Call\Model\EO_CallUserLog unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Call\Model\EO_CallUserLog wakeUp($data)
	 */
	class EO_CallUserLog extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Call\Model\CallUserLogTable */
		static public $dataClass = '\Bitrix\Call\Model\CallUserLogTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Call\Model {
	/**
	 * EO_CallUserLog_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \string[] getSourceTypeList()
	 * @method \string[] fillSourceType()
	 * @method \int[] getSourceCallIdList()
	 * @method \int[] fillSourceCallId()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \string[] getStatusList()
	 * @method \string[] fillStatus()
	 * @method \Bitrix\Main\Type\DateTime[] getStatusTimeList()
	 * @method \Bitrix\Main\Type\DateTime[] fillStatusTime()
	 * @method \Bitrix\Main\EO_User[] getUserList()
	 * @method \Bitrix\Call\Model\EO_CallUserLog_Collection getUserCollection()
	 * @method \Bitrix\Main\EO_User_Collection fillUser()
	 * @method \Bitrix\Voximplant\EO_Statistic[] getVoximplantStatList()
	 * @method \Bitrix\Call\Model\EO_CallUserLog_Collection getVoximplantStatCollection()
	 * @method \Bitrix\Voximplant\EO_Statistic_Collection fillVoximplantStat()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Call\Model\EO_CallUserLog $object)
	 * @method bool has(\Bitrix\Call\Model\EO_CallUserLog $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Call\Model\EO_CallUserLog getByPrimary($primary)
	 * @method \Bitrix\Call\Model\EO_CallUserLog[] getAll()
	 * @method bool remove(\Bitrix\Call\Model\EO_CallUserLog $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Call\Model\EO_CallUserLog_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Call\Model\EO_CallUserLog current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Call\Model\EO_CallUserLog_Collection merge(?\Bitrix\Call\Model\EO_CallUserLog_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Call\Model\EO_CallUserLog|null find(callable $callback)
	 * @method \Bitrix\Call\Model\EO_CallUserLog_Collection filter(callable $callback)
	 */
	class EO_CallUserLog_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Call\Model\CallUserLogTable */
		static public $dataClass = '\Bitrix\Call\Model\CallUserLogTable';
	}
}
namespace Bitrix\Call\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_CallUserLog_Result exec()
	 * @method \Bitrix\Call\Model\EO_CallUserLog fetchObject()
	 * @method \Bitrix\Call\Model\EO_CallUserLog_Collection fetchCollection()
	 */
	class EO_CallUserLog_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Call\Model\EO_CallUserLog fetchObject()
	 * @method \Bitrix\Call\Model\EO_CallUserLog_Collection fetchCollection()
	 */
	class EO_CallUserLog_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Call\Model\EO_CallUserLog createObject($setDefaultValues = true)
	 * @method \Bitrix\Call\Model\EO_CallUserLog_Collection createCollection()
	 * @method \Bitrix\Call\Model\EO_CallUserLog wakeUpObject($row)
	 * @method \Bitrix\Call\Model\EO_CallUserLog_Collection wakeUpCollection($rows)
	 */
	class EO_CallUserLog_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Call\Model\ConferenceUserRoleTable:call/lib/model/conferenceuserroletable.php */
namespace Bitrix\Call\Model {
	/**
	 * EO_ConferenceUserRole
	 * @see \Bitrix\Call\Model\ConferenceUserRoleTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getConferenceId()
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole setConferenceId(\int|\Bitrix\Main\DB\SqlExpression $conferenceId)
	 * @method bool hasConferenceId()
	 * @method bool isConferenceIdFilled()
	 * @method bool isConferenceIdChanged()
	 * @method \int getUserId()
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \string getRole()
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole setRole(\string|\Bitrix\Main\DB\SqlExpression $role)
	 * @method bool hasRole()
	 * @method bool isRoleFilled()
	 * @method bool isRoleChanged()
	 * @method \string remindActualRole()
	 * @method \string requireRole()
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole resetRole()
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole unsetRole()
	 * @method \string fillRole()
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
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole set($fieldName, $value)
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole reset($fieldName)
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Call\Model\EO_ConferenceUserRole wakeUp($data)
	 */
	class EO_ConferenceUserRole extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Call\Model\ConferenceUserRoleTable */
		static public $dataClass = '\Bitrix\Call\Model\ConferenceUserRoleTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Call\Model {
	/**
	 * EO_ConferenceUserRole_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getConferenceIdList()
	 * @method \int[] getUserIdList()
	 * @method \string[] getRoleList()
	 * @method \string[] fillRole()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Call\Model\EO_ConferenceUserRole $object)
	 * @method bool has(\Bitrix\Call\Model\EO_ConferenceUserRole $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole getByPrimary($primary)
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole[] getAll()
	 * @method bool remove(\Bitrix\Call\Model\EO_ConferenceUserRole $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Call\Model\EO_ConferenceUserRole_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole_Collection merge(?\Bitrix\Call\Model\EO_ConferenceUserRole_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole|null find(callable $callback)
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole_Collection filter(callable $callback)
	 */
	class EO_ConferenceUserRole_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Call\Model\ConferenceUserRoleTable */
		static public $dataClass = '\Bitrix\Call\Model\ConferenceUserRoleTable';
	}
}
namespace Bitrix\Call\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_ConferenceUserRole_Result exec()
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole fetchObject()
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole_Collection fetchCollection()
	 */
	class EO_ConferenceUserRole_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole fetchObject()
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole_Collection fetchCollection()
	 */
	class EO_ConferenceUserRole_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole createObject($setDefaultValues = true)
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole_Collection createCollection()
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole wakeUpObject($row)
	 * @method \Bitrix\Call\Model\EO_ConferenceUserRole_Collection wakeUpCollection($rows)
	 */
	class EO_ConferenceUserRole_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Call\Model\CallAITaskTable:call/lib/model/callaitasktable.php */
namespace Bitrix\Call\Model {
	/**
	 * EO_CallAITask
	 * @see \Bitrix\Call\Model\CallAITaskTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Call\Model\EO_CallAITask setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getCallId()
	 * @method \Bitrix\Call\Model\EO_CallAITask setCallId(\int|\Bitrix\Main\DB\SqlExpression $callId)
	 * @method bool hasCallId()
	 * @method bool isCallIdFilled()
	 * @method bool isCallIdChanged()
	 * @method \int remindActualCallId()
	 * @method \int requireCallId()
	 * @method \Bitrix\Call\Model\EO_CallAITask resetCallId()
	 * @method \Bitrix\Call\Model\EO_CallAITask unsetCallId()
	 * @method \int fillCallId()
	 * @method null|\int getTrackId()
	 * @method \Bitrix\Call\Model\EO_CallAITask setTrackId(null|\int|\Bitrix\Main\DB\SqlExpression $trackId)
	 * @method bool hasTrackId()
	 * @method bool isTrackIdFilled()
	 * @method bool isTrackIdChanged()
	 * @method null|\int remindActualTrackId()
	 * @method null|\int requireTrackId()
	 * @method \Bitrix\Call\Model\EO_CallAITask resetTrackId()
	 * @method \Bitrix\Call\Model\EO_CallAITask unsetTrackId()
	 * @method null|\int fillTrackId()
	 * @method null|\int getOutcomeId()
	 * @method \Bitrix\Call\Model\EO_CallAITask setOutcomeId(null|\int|\Bitrix\Main\DB\SqlExpression $outcomeId)
	 * @method bool hasOutcomeId()
	 * @method bool isOutcomeIdFilled()
	 * @method bool isOutcomeIdChanged()
	 * @method null|\int remindActualOutcomeId()
	 * @method null|\int requireOutcomeId()
	 * @method \Bitrix\Call\Model\EO_CallAITask resetOutcomeId()
	 * @method \Bitrix\Call\Model\EO_CallAITask unsetOutcomeId()
	 * @method null|\int fillOutcomeId()
	 * @method null|\string getType()
	 * @method \Bitrix\Call\Model\EO_CallAITask setType(null|\string|\Bitrix\Main\DB\SqlExpression $type)
	 * @method bool hasType()
	 * @method bool isTypeFilled()
	 * @method bool isTypeChanged()
	 * @method null|\string remindActualType()
	 * @method null|\string requireType()
	 * @method \Bitrix\Call\Model\EO_CallAITask resetType()
	 * @method \Bitrix\Call\Model\EO_CallAITask unsetType()
	 * @method null|\string fillType()
	 * @method \Bitrix\Main\Type\DateTime getDateCreate()
	 * @method \Bitrix\Call\Model\EO_CallAITask setDateCreate(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateCreate)
	 * @method bool hasDateCreate()
	 * @method bool isDateCreateFilled()
	 * @method bool isDateCreateChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualDateCreate()
	 * @method \Bitrix\Main\Type\DateTime requireDateCreate()
	 * @method \Bitrix\Call\Model\EO_CallAITask resetDateCreate()
	 * @method \Bitrix\Call\Model\EO_CallAITask unsetDateCreate()
	 * @method \Bitrix\Main\Type\DateTime fillDateCreate()
	 * @method null|\Bitrix\Main\Type\DateTime getDateFinished()
	 * @method \Bitrix\Call\Model\EO_CallAITask setDateFinished(null|\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $dateFinished)
	 * @method bool hasDateFinished()
	 * @method bool isDateFinishedFilled()
	 * @method bool isDateFinishedChanged()
	 * @method null|\Bitrix\Main\Type\DateTime remindActualDateFinished()
	 * @method null|\Bitrix\Main\Type\DateTime requireDateFinished()
	 * @method \Bitrix\Call\Model\EO_CallAITask resetDateFinished()
	 * @method \Bitrix\Call\Model\EO_CallAITask unsetDateFinished()
	 * @method null|\Bitrix\Main\Type\DateTime fillDateFinished()
	 * @method \string getStatus()
	 * @method \Bitrix\Call\Model\EO_CallAITask setStatus(\string|\Bitrix\Main\DB\SqlExpression $status)
	 * @method bool hasStatus()
	 * @method bool isStatusFilled()
	 * @method bool isStatusChanged()
	 * @method \string remindActualStatus()
	 * @method \string requireStatus()
	 * @method \Bitrix\Call\Model\EO_CallAITask resetStatus()
	 * @method \Bitrix\Call\Model\EO_CallAITask unsetStatus()
	 * @method \string fillStatus()
	 * @method null|\string getHash()
	 * @method \Bitrix\Call\Model\EO_CallAITask setHash(null|\string|\Bitrix\Main\DB\SqlExpression $hash)
	 * @method bool hasHash()
	 * @method bool isHashFilled()
	 * @method bool isHashChanged()
	 * @method null|\string remindActualHash()
	 * @method null|\string requireHash()
	 * @method \Bitrix\Call\Model\EO_CallAITask resetHash()
	 * @method \Bitrix\Call\Model\EO_CallAITask unsetHash()
	 * @method null|\string fillHash()
	 * @method null|\string getLanguageId()
	 * @method \Bitrix\Call\Model\EO_CallAITask setLanguageId(null|\string|\Bitrix\Main\DB\SqlExpression $languageId)
	 * @method bool hasLanguageId()
	 * @method bool isLanguageIdFilled()
	 * @method bool isLanguageIdChanged()
	 * @method null|\string remindActualLanguageId()
	 * @method null|\string requireLanguageId()
	 * @method \Bitrix\Call\Model\EO_CallAITask resetLanguageId()
	 * @method \Bitrix\Call\Model\EO_CallAITask unsetLanguageId()
	 * @method null|\string fillLanguageId()
	 * @method null|\string getErrorCode()
	 * @method \Bitrix\Call\Model\EO_CallAITask setErrorCode(null|\string|\Bitrix\Main\DB\SqlExpression $errorCode)
	 * @method bool hasErrorCode()
	 * @method bool isErrorCodeFilled()
	 * @method bool isErrorCodeChanged()
	 * @method null|\string remindActualErrorCode()
	 * @method null|\string requireErrorCode()
	 * @method \Bitrix\Call\Model\EO_CallAITask resetErrorCode()
	 * @method \Bitrix\Call\Model\EO_CallAITask unsetErrorCode()
	 * @method null|\string fillErrorCode()
	 * @method null|\string getErrorMessage()
	 * @method \Bitrix\Call\Model\EO_CallAITask setErrorMessage(null|\string|\Bitrix\Main\DB\SqlExpression $errorMessage)
	 * @method bool hasErrorMessage()
	 * @method bool isErrorMessageFilled()
	 * @method bool isErrorMessageChanged()
	 * @method null|\string remindActualErrorMessage()
	 * @method null|\string requireErrorMessage()
	 * @method \Bitrix\Call\Model\EO_CallAITask resetErrorMessage()
	 * @method \Bitrix\Call\Model\EO_CallAITask unsetErrorMessage()
	 * @method null|\string fillErrorMessage()
	 * @method \Bitrix\Call\Track getTrack()
	 * @method \Bitrix\Call\Track remindActualTrack()
	 * @method \Bitrix\Call\Track requireTrack()
	 * @method \Bitrix\Call\Model\EO_CallAITask setTrack(\Bitrix\Call\Track $object)
	 * @method \Bitrix\Call\Model\EO_CallAITask resetTrack()
	 * @method \Bitrix\Call\Model\EO_CallAITask unsetTrack()
	 * @method bool hasTrack()
	 * @method bool isTrackFilled()
	 * @method bool isTrackChanged()
	 * @method \Bitrix\Call\Track fillTrack()
	 * @method \Bitrix\Call\Model\EO_Call getCall()
	 * @method \Bitrix\Call\Model\EO_Call remindActualCall()
	 * @method \Bitrix\Call\Model\EO_Call requireCall()
	 * @method \Bitrix\Call\Model\EO_CallAITask setCall(\Bitrix\Call\Model\EO_Call $object)
	 * @method \Bitrix\Call\Model\EO_CallAITask resetCall()
	 * @method \Bitrix\Call\Model\EO_CallAITask unsetCall()
	 * @method bool hasCall()
	 * @method bool isCallFilled()
	 * @method bool isCallChanged()
	 * @method \Bitrix\Call\Model\EO_Call fillCall()
	 * @method \Bitrix\Call\Integration\AI\Outcome getOutcome()
	 * @method \Bitrix\Call\Integration\AI\Outcome remindActualOutcome()
	 * @method \Bitrix\Call\Integration\AI\Outcome requireOutcome()
	 * @method \Bitrix\Call\Model\EO_CallAITask setOutcome(\Bitrix\Call\Integration\AI\Outcome $object)
	 * @method \Bitrix\Call\Model\EO_CallAITask resetOutcome()
	 * @method \Bitrix\Call\Model\EO_CallAITask unsetOutcome()
	 * @method bool hasOutcome()
	 * @method bool isOutcomeFilled()
	 * @method bool isOutcomeChanged()
	 * @method \Bitrix\Call\Integration\AI\Outcome fillOutcome()
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
	 * @method \Bitrix\Call\Model\EO_CallAITask set($fieldName, $value)
	 * @method \Bitrix\Call\Model\EO_CallAITask reset($fieldName)
	 * @method \Bitrix\Call\Model\EO_CallAITask unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Call\Model\EO_CallAITask wakeUp($data)
	 */
	class EO_CallAITask extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Call\Model\CallAITaskTable */
		static public $dataClass = '\Bitrix\Call\Model\CallAITaskTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Call\Model {
	/**
	 * EO_CallAITask_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getCallIdList()
	 * @method \int[] fillCallId()
	 * @method null|\int[] getTrackIdList()
	 * @method null|\int[] fillTrackId()
	 * @method null|\int[] getOutcomeIdList()
	 * @method null|\int[] fillOutcomeId()
	 * @method null|\string[] getTypeList()
	 * @method null|\string[] fillType()
	 * @method \Bitrix\Main\Type\DateTime[] getDateCreateList()
	 * @method \Bitrix\Main\Type\DateTime[] fillDateCreate()
	 * @method null|\Bitrix\Main\Type\DateTime[] getDateFinishedList()
	 * @method null|\Bitrix\Main\Type\DateTime[] fillDateFinished()
	 * @method \string[] getStatusList()
	 * @method \string[] fillStatus()
	 * @method null|\string[] getHashList()
	 * @method null|\string[] fillHash()
	 * @method null|\string[] getLanguageIdList()
	 * @method null|\string[] fillLanguageId()
	 * @method null|\string[] getErrorCodeList()
	 * @method null|\string[] fillErrorCode()
	 * @method null|\string[] getErrorMessageList()
	 * @method null|\string[] fillErrorMessage()
	 * @method \Bitrix\Call\Track[] getTrackList()
	 * @method \Bitrix\Call\Model\EO_CallAITask_Collection getTrackCollection()
	 * @method \Bitrix\Call\Track\TrackCollection fillTrack()
	 * @method \Bitrix\Call\Model\EO_Call[] getCallList()
	 * @method \Bitrix\Call\Model\EO_CallAITask_Collection getCallCollection()
	 * @method \Bitrix\Call\Model\EO_Call_Collection fillCall()
	 * @method \Bitrix\Call\Integration\AI\Outcome[] getOutcomeList()
	 * @method \Bitrix\Call\Model\EO_CallAITask_Collection getOutcomeCollection()
	 * @method \Bitrix\Call\Integration\AI\Outcome\OutcomeCollection fillOutcome()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Call\Model\EO_CallAITask $object)
	 * @method bool has(\Bitrix\Call\Model\EO_CallAITask $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Call\Model\EO_CallAITask getByPrimary($primary)
	 * @method \Bitrix\Call\Model\EO_CallAITask[] getAll()
	 * @method bool remove(\Bitrix\Call\Model\EO_CallAITask $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Call\Model\EO_CallAITask_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Call\Model\EO_CallAITask current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Call\Model\EO_CallAITask_Collection merge(?\Bitrix\Call\Model\EO_CallAITask_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Call\Model\EO_CallAITask|null find(callable $callback)
	 * @method \Bitrix\Call\Model\EO_CallAITask_Collection filter(callable $callback)
	 */
	class EO_CallAITask_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Call\Model\CallAITaskTable */
		static public $dataClass = '\Bitrix\Call\Model\CallAITaskTable';
	}
}
namespace Bitrix\Call\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_CallAITask_Result exec()
	 * @method \Bitrix\Call\Model\EO_CallAITask fetchObject()
	 * @method \Bitrix\Call\Model\EO_CallAITask_Collection fetchCollection()
	 */
	class EO_CallAITask_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Call\Model\EO_CallAITask fetchObject()
	 * @method \Bitrix\Call\Model\EO_CallAITask_Collection fetchCollection()
	 */
	class EO_CallAITask_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Call\Model\EO_CallAITask createObject($setDefaultValues = true)
	 * @method \Bitrix\Call\Model\EO_CallAITask_Collection createCollection()
	 * @method \Bitrix\Call\Model\EO_CallAITask wakeUpObject($row)
	 * @method \Bitrix\Call\Model\EO_CallAITask_Collection wakeUpCollection($rows)
	 */
	class EO_CallAITask_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Call\Model\CallUserLogIndexTable:call/lib/model/calluserlogindextable.php */
namespace Bitrix\Call\Model {
	/**
	 * EO_CallUserLogIndex
	 * @see \Bitrix\Call\Model\CallUserLogIndexTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getUserlogId()
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex setUserlogId(\int|\Bitrix\Main\DB\SqlExpression $userlogId)
	 * @method bool hasUserlogId()
	 * @method bool isUserlogIdFilled()
	 * @method bool isUserlogIdChanged()
	 * @method \string getSearchTitle()
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex setSearchTitle(\string|\Bitrix\Main\DB\SqlExpression $searchTitle)
	 * @method bool hasSearchTitle()
	 * @method bool isSearchTitleFilled()
	 * @method bool isSearchTitleChanged()
	 * @method \string remindActualSearchTitle()
	 * @method \string requireSearchTitle()
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex resetSearchTitle()
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex unsetSearchTitle()
	 * @method \string fillSearchTitle()
	 * @method \string getSearchContent()
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex setSearchContent(\string|\Bitrix\Main\DB\SqlExpression $searchContent)
	 * @method bool hasSearchContent()
	 * @method bool isSearchContentFilled()
	 * @method bool isSearchContentChanged()
	 * @method \string remindActualSearchContent()
	 * @method \string requireSearchContent()
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex resetSearchContent()
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex unsetSearchContent()
	 * @method \string fillSearchContent()
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
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex set($fieldName, $value)
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex reset($fieldName)
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Call\Model\EO_CallUserLogIndex wakeUp($data)
	 */
	class EO_CallUserLogIndex extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Call\Model\CallUserLogIndexTable */
		static public $dataClass = '\Bitrix\Call\Model\CallUserLogIndexTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Call\Model {
	/**
	 * EO_CallUserLogIndex_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getUserlogIdList()
	 * @method \string[] getSearchTitleList()
	 * @method \string[] fillSearchTitle()
	 * @method \string[] getSearchContentList()
	 * @method \string[] fillSearchContent()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Call\Model\EO_CallUserLogIndex $object)
	 * @method bool has(\Bitrix\Call\Model\EO_CallUserLogIndex $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex getByPrimary($primary)
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex[] getAll()
	 * @method bool remove(\Bitrix\Call\Model\EO_CallUserLogIndex $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Call\Model\EO_CallUserLogIndex_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex_Collection merge(?\Bitrix\Call\Model\EO_CallUserLogIndex_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex|null find(callable $callback)
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex_Collection filter(callable $callback)
	 */
	class EO_CallUserLogIndex_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Call\Model\CallUserLogIndexTable */
		static public $dataClass = '\Bitrix\Call\Model\CallUserLogIndexTable';
	}
}
namespace Bitrix\Call\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_CallUserLogIndex_Result exec()
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex fetchObject()
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex_Collection fetchCollection()
	 */
	class EO_CallUserLogIndex_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex fetchObject()
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex_Collection fetchCollection()
	 */
	class EO_CallUserLogIndex_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex createObject($setDefaultValues = true)
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex_Collection createCollection()
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex wakeUpObject($row)
	 * @method \Bitrix\Call\Model\EO_CallUserLogIndex_Collection wakeUpCollection($rows)
	 */
	class EO_CallUserLogIndex_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Call\Model\ConferenceTable:call/lib/model/conferencetable.php */
namespace Bitrix\Call\Model {
	/**
	 * EO_Conference
	 * @see \Bitrix\Call\Model\ConferenceTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Call\Model\EO_Conference setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getAliasId()
	 * @method \Bitrix\Call\Model\EO_Conference setAliasId(\int|\Bitrix\Main\DB\SqlExpression $aliasId)
	 * @method bool hasAliasId()
	 * @method bool isAliasIdFilled()
	 * @method bool isAliasIdChanged()
	 * @method \int remindActualAliasId()
	 * @method \int requireAliasId()
	 * @method \Bitrix\Call\Model\EO_Conference resetAliasId()
	 * @method \Bitrix\Call\Model\EO_Conference unsetAliasId()
	 * @method \int fillAliasId()
	 * @method \string getPassword()
	 * @method \Bitrix\Call\Model\EO_Conference setPassword(\string|\Bitrix\Main\DB\SqlExpression $password)
	 * @method bool hasPassword()
	 * @method bool isPasswordFilled()
	 * @method bool isPasswordChanged()
	 * @method \string remindActualPassword()
	 * @method \string requirePassword()
	 * @method \Bitrix\Call\Model\EO_Conference resetPassword()
	 * @method \Bitrix\Call\Model\EO_Conference unsetPassword()
	 * @method \string fillPassword()
	 * @method \string getInvitation()
	 * @method \Bitrix\Call\Model\EO_Conference setInvitation(\string|\Bitrix\Main\DB\SqlExpression $invitation)
	 * @method bool hasInvitation()
	 * @method bool isInvitationFilled()
	 * @method bool isInvitationChanged()
	 * @method \string remindActualInvitation()
	 * @method \string requireInvitation()
	 * @method \Bitrix\Call\Model\EO_Conference resetInvitation()
	 * @method \Bitrix\Call\Model\EO_Conference unsetInvitation()
	 * @method \string fillInvitation()
	 * @method \Bitrix\Main\Type\DateTime getConferenceStart()
	 * @method \Bitrix\Call\Model\EO_Conference setConferenceStart(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $conferenceStart)
	 * @method bool hasConferenceStart()
	 * @method bool isConferenceStartFilled()
	 * @method bool isConferenceStartChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualConferenceStart()
	 * @method \Bitrix\Main\Type\DateTime requireConferenceStart()
	 * @method \Bitrix\Call\Model\EO_Conference resetConferenceStart()
	 * @method \Bitrix\Call\Model\EO_Conference unsetConferenceStart()
	 * @method \Bitrix\Main\Type\DateTime fillConferenceStart()
	 * @method \Bitrix\Main\Type\DateTime getConferenceEnd()
	 * @method \Bitrix\Call\Model\EO_Conference setConferenceEnd(\Bitrix\Main\Type\DateTime|\Bitrix\Main\DB\SqlExpression $conferenceEnd)
	 * @method bool hasConferenceEnd()
	 * @method bool isConferenceEndFilled()
	 * @method bool isConferenceEndChanged()
	 * @method \Bitrix\Main\Type\DateTime remindActualConferenceEnd()
	 * @method \Bitrix\Main\Type\DateTime requireConferenceEnd()
	 * @method \Bitrix\Call\Model\EO_Conference resetConferenceEnd()
	 * @method \Bitrix\Call\Model\EO_Conference unsetConferenceEnd()
	 * @method \Bitrix\Main\Type\DateTime fillConferenceEnd()
	 * @method \string getIsBroadcast()
	 * @method \Bitrix\Call\Model\EO_Conference setIsBroadcast(\string|\Bitrix\Main\DB\SqlExpression $isBroadcast)
	 * @method bool hasIsBroadcast()
	 * @method bool isIsBroadcastFilled()
	 * @method bool isIsBroadcastChanged()
	 * @method \string remindActualIsBroadcast()
	 * @method \string requireIsBroadcast()
	 * @method \Bitrix\Call\Model\EO_Conference resetIsBroadcast()
	 * @method \Bitrix\Call\Model\EO_Conference unsetIsBroadcast()
	 * @method \string fillIsBroadcast()
	 * @method \Bitrix\Im\Model\EO_Alias getAlias()
	 * @method \Bitrix\Im\Model\EO_Alias remindActualAlias()
	 * @method \Bitrix\Im\Model\EO_Alias requireAlias()
	 * @method \Bitrix\Call\Model\EO_Conference setAlias(\Bitrix\Im\Model\EO_Alias $object)
	 * @method \Bitrix\Call\Model\EO_Conference resetAlias()
	 * @method \Bitrix\Call\Model\EO_Conference unsetAlias()
	 * @method bool hasAlias()
	 * @method bool isAliasFilled()
	 * @method bool isAliasChanged()
	 * @method \Bitrix\Im\Model\EO_Alias fillAlias()
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
	 * @method \Bitrix\Call\Model\EO_Conference set($fieldName, $value)
	 * @method \Bitrix\Call\Model\EO_Conference reset($fieldName)
	 * @method \Bitrix\Call\Model\EO_Conference unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Call\Model\EO_Conference wakeUp($data)
	 */
	class EO_Conference extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Call\Model\ConferenceTable */
		static public $dataClass = '\Bitrix\Call\Model\ConferenceTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Call\Model {
	/**
	 * EO_Conference_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getAliasIdList()
	 * @method \int[] fillAliasId()
	 * @method \string[] getPasswordList()
	 * @method \string[] fillPassword()
	 * @method \string[] getInvitationList()
	 * @method \string[] fillInvitation()
	 * @method \Bitrix\Main\Type\DateTime[] getConferenceStartList()
	 * @method \Bitrix\Main\Type\DateTime[] fillConferenceStart()
	 * @method \Bitrix\Main\Type\DateTime[] getConferenceEndList()
	 * @method \Bitrix\Main\Type\DateTime[] fillConferenceEnd()
	 * @method \string[] getIsBroadcastList()
	 * @method \string[] fillIsBroadcast()
	 * @method \Bitrix\Im\Model\EO_Alias[] getAliasList()
	 * @method \Bitrix\Call\Model\EO_Conference_Collection getAliasCollection()
	 * @method \Bitrix\Im\Model\EO_Alias_Collection fillAlias()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Call\Model\EO_Conference $object)
	 * @method bool has(\Bitrix\Call\Model\EO_Conference $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Call\Model\EO_Conference getByPrimary($primary)
	 * @method \Bitrix\Call\Model\EO_Conference[] getAll()
	 * @method bool remove(\Bitrix\Call\Model\EO_Conference $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Call\Model\EO_Conference_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Call\Model\EO_Conference current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Call\Model\EO_Conference_Collection merge(?\Bitrix\Call\Model\EO_Conference_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Call\Model\EO_Conference|null find(callable $callback)
	 * @method \Bitrix\Call\Model\EO_Conference_Collection filter(callable $callback)
	 */
	class EO_Conference_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Call\Model\ConferenceTable */
		static public $dataClass = '\Bitrix\Call\Model\ConferenceTable';
	}
}
namespace Bitrix\Call\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_Conference_Result exec()
	 * @method \Bitrix\Call\Model\EO_Conference fetchObject()
	 * @method \Bitrix\Call\Model\EO_Conference_Collection fetchCollection()
	 */
	class EO_Conference_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Call\Model\EO_Conference fetchObject()
	 * @method \Bitrix\Call\Model\EO_Conference_Collection fetchCollection()
	 */
	class EO_Conference_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Call\Model\EO_Conference createObject($setDefaultValues = true)
	 * @method \Bitrix\Call\Model\EO_Conference_Collection createCollection()
	 * @method \Bitrix\Call\Model\EO_Conference wakeUpObject($row)
	 * @method \Bitrix\Call\Model\EO_Conference_Collection wakeUpCollection($rows)
	 */
	class EO_Conference_Entity extends \Bitrix\Main\ORM\Entity {}
}
/* ORMENTITYANNOTATION:Bitrix\Call\Model\CallUserLogCountersTable:call/lib/model/calluserlogcounterstable.php */
namespace Bitrix\Call\Model {
	/**
	 * EO_CallUserLogCounters
	 * @see \Bitrix\Call\Model\CallUserLogCountersTable
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int getId()
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters setId(\int|\Bitrix\Main\DB\SqlExpression $id)
	 * @method bool hasId()
	 * @method bool isIdFilled()
	 * @method bool isIdChanged()
	 * @method \int getUserlogId()
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters setUserlogId(\int|\Bitrix\Main\DB\SqlExpression $userlogId)
	 * @method bool hasUserlogId()
	 * @method bool isUserlogIdFilled()
	 * @method bool isUserlogIdChanged()
	 * @method \int remindActualUserlogId()
	 * @method \int requireUserlogId()
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters resetUserlogId()
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters unsetUserlogId()
	 * @method \int fillUserlogId()
	 * @method \int getUserId()
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters setUserId(\int|\Bitrix\Main\DB\SqlExpression $userId)
	 * @method bool hasUserId()
	 * @method bool isUserIdFilled()
	 * @method bool isUserIdChanged()
	 * @method \int remindActualUserId()
	 * @method \int requireUserId()
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters resetUserId()
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters unsetUserId()
	 * @method \int fillUserId()
	 * @method \Bitrix\Call\Model\EO_CallUserLog getUserlog()
	 * @method \Bitrix\Call\Model\EO_CallUserLog remindActualUserlog()
	 * @method \Bitrix\Call\Model\EO_CallUserLog requireUserlog()
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters setUserlog(\Bitrix\Call\Model\EO_CallUserLog $object)
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters resetUserlog()
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters unsetUserlog()
	 * @method bool hasUserlog()
	 * @method bool isUserlogFilled()
	 * @method bool isUserlogChanged()
	 * @method \Bitrix\Call\Model\EO_CallUserLog fillUserlog()
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
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters set($fieldName, $value)
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters reset($fieldName)
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters unset($fieldName)
	 * @method void addTo($fieldName, $value)
	 * @method void removeFrom($fieldName, $value)
	 * @method void removeAll($fieldName)
	 * @method \Bitrix\Main\ORM\Data\Result delete()
	 * @method mixed fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method mixed[] collectValues($valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL)
	 * @method \Bitrix\Main\ORM\Data\AddResult|\Bitrix\Main\ORM\Data\UpdateResult|\Bitrix\Main\ORM\Data\Result save()
	 * @method static \Bitrix\Call\Model\EO_CallUserLogCounters wakeUp($data)
	 */
	class EO_CallUserLogCounters extends \Bitrix\Main\ORM\Objectify\EntityObject {
		/* @var \Bitrix\Call\Model\CallUserLogCountersTable */
		static public $dataClass = '\Bitrix\Call\Model\CallUserLogCountersTable';
		/**
		 * @param bool|array $setDefaultValues
		 */
		public function __construct($setDefaultValues = true) {}
	}
}
namespace Bitrix\Call\Model {
	/**
	 * EO_CallUserLogCounters_Collection
	 *
	 * Custom methods:
	 * ---------------
	 *
	 * @method \int[] getIdList()
	 * @method \int[] getUserlogIdList()
	 * @method \int[] fillUserlogId()
	 * @method \int[] getUserIdList()
	 * @method \int[] fillUserId()
	 * @method \Bitrix\Call\Model\EO_CallUserLog[] getUserlogList()
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters_Collection getUserlogCollection()
	 * @method \Bitrix\Call\Model\EO_CallUserLog_Collection fillUserlog()
	 *
	 * Common methods:
	 * ---------------
	 *
	 * @property-read \Bitrix\Main\ORM\Entity $entity
	 * @method void add(\Bitrix\Call\Model\EO_CallUserLogCounters $object)
	 * @method bool has(\Bitrix\Call\Model\EO_CallUserLogCounters $object)
	 * @method bool hasByPrimary($primary)
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters getByPrimary($primary)
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters[] getAll()
	 * @method bool remove(\Bitrix\Call\Model\EO_CallUserLogCounters $object)
	 * @method void removeByPrimary($primary)
	 * @method array|\Bitrix\Main\ORM\Objectify\Collection|null fill($fields = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL) flag or array of field names
	 * @method static \Bitrix\Call\Model\EO_CallUserLogCounters_Collection wakeUp($data)
	 * @method \Bitrix\Main\ORM\Data\Result save($ignoreEvents = false)
	 * @method void offsetSet() ArrayAccess
	 * @method void offsetExists() ArrayAccess
	 * @method void offsetUnset() ArrayAccess
	 * @method void offsetGet() ArrayAccess
	 * @method void rewind() Iterator
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters current() Iterator
	 * @method mixed key() Iterator
	 * @method void next() Iterator
	 * @method bool valid() Iterator
	 * @method int count() Countable
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters_Collection merge(?\Bitrix\Call\Model\EO_CallUserLogCounters_Collection $collection)
	 * @method bool isEmpty()
	 * @method array collectValues(int $valuesType = \Bitrix\Main\ORM\Objectify\Values::ALL, int $fieldsMask = \Bitrix\Main\ORM\Fields\FieldTypeMask::ALL, bool $recursive = false)
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters|null find(callable $callback)
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters_Collection filter(callable $callback)
	 */
	class EO_CallUserLogCounters_Collection extends \Bitrix\Main\ORM\Objectify\Collection implements \ArrayAccess, \Iterator, \Countable {
		/* @var \Bitrix\Call\Model\CallUserLogCountersTable */
		static public $dataClass = '\Bitrix\Call\Model\CallUserLogCountersTable';
	}
}
namespace Bitrix\Call\Model {
	/**
	 * Common methods:
	 * ---------------
	 *
	 * @method EO_CallUserLogCounters_Result exec()
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters fetchObject()
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters_Collection fetchCollection()
	 */
	class EO_CallUserLogCounters_Query extends \Bitrix\Main\ORM\Query\Query {}
	/**
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters fetchObject()
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters_Collection fetchCollection()
	 */
	class EO_CallUserLogCounters_Result extends \Bitrix\Main\ORM\Query\Result {}
	/**
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters createObject($setDefaultValues = true)
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters_Collection createCollection()
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters wakeUpObject($row)
	 * @method \Bitrix\Call\Model\EO_CallUserLogCounters_Collection wakeUpCollection($rows)
	 */
	class EO_CallUserLogCounters_Entity extends \Bitrix\Main\ORM\Entity {}
}