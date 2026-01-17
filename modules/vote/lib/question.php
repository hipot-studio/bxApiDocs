<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage vote
 * @copyright 2001-2016 Bitrix
 */
namespace Bitrix\Vote;
use \Bitrix\Main\Entity;

/**
 * Class VoteTable
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> ACTIVE bool mandatory default 'Y',
 * <li> TIMESTAMP_X datetime,
 * <li> VOTE_ID int,
 * <li> C_SORT int,
 * <li> COUNTER int,
 * <li> QUESTION text,
 * <li> QUESTION_TYPE string(4),
 * <li> IMAGE_ID int,
 * <li> DIAGRAM bool mandatory default 'Y',
 * <li> DIAGRAM_TYPE string(10) mandatory default 'histogram' || 'circle',
 * <li> REQUIRED bool mandatory default 'N',
 * </ul>
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Question_Query query()
 * @method static EO_Question_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Question_Result getById($id)
 * @method static EO_Question_Result getList(array $parameters = [])
 * @method static EO_Question_Entity getEntity()
 * @method static \Bitrix\Vote\EO_Question createObject($setDefaultValues = true)
 * @method static \Bitrix\Vote\EO_Question_Collection createCollection()
 * @method static \Bitrix\Vote\EO_Question wakeUpObject($row)
 * @method static \Bitrix\Vote\EO_Question_Collection wakeUpCollection($rows)
 */
class QuestionTable extends Entity\DataManager
{
	/**
	 * Returns DB table name for entity
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_vote_question';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return array(
			'ID' => array(
				'data_type' => 'integer',
				'primary' => true,
				'autocomplete' => true,
			),
			'ACTIVE' => array(
				'data_type' => 'boolean',
				'values' => array('N', 'Y'),
				'default_value' => 'Y',
			),
			'TIMESTAMP_X' => array(
				'data_type' => 'datetime',
			),
			'VOTE_ID' => array(
				'data_type' => 'integer',
			),
			'C_SORT' => array(
				'data_type' => 'integer',
			),
			'COUNTER' => array(
				'data_type' => 'integer',
			),
			'QUESTION' => array(
				'data_type' => 'text',
				'save_data_modification' => array('\Bitrix\Main\Text\Emoji', 'getSaveModificator'),
				'fetch_data_modification' => array('\Bitrix\Main\Text\Emoji', 'getFetchModificator'),
			),
			'QUESTION_TYPE' => array(
				'data_type' => 'enum',
				'values' => array("text", "html"),
				'default_value' => "text",
			),
			'IMAGE_ID' =>  array(
				'data_type' => 'integer',
			),
			'IMAGE' =>  array(
				'data_type' => '\Bitrix\Main\FileTable',
				'reference' => array(
					'=this.IMAGE_ID' => 'ref.ID',
				),
				'join_type' => 'LEFT',
			),
			'DIAGRAM' => array(
				'data_type' => 'boolean',
				'values' => array('N', 'Y'),
				'default_value' => 'Y',
			),
			'DIAGRAM_TYPE' => array(
				'data_type' => 'enum',
				'values' => array("histogram", "circle"),
				'default_value' => "histogram",
			),
			'REQUIRED' => array(
				'data_type' => 'boolean',
				'values' => array('N', 'Y'),
				'default_value' => 'N',
			),
			'FIELD_TYPE' => array(
				'data_type' => 'enum',
				'values' => \Bitrix\Vote\QuestionTypes::getValues(),
				'default_value' => '0',
			),
			'VOTE' => array(
				'data_type' => '\Bitrix\Vote\VoteTable',
				'reference' => array(
					'=this.VOTE_ID' => 'ref.ID',
				),
				'join_type' => 'RIGHT',
			),
			'ANSWER' => array(
				'data_type' => '\Bitrix\Vote\AnswerTable',
				'reference' => array(
					'=this.ID' => 'ref.QUESTION_ID',
				),
				'join_type' => 'LEFT',
			)
		);
	}
	/**
	 * @param array $id Question IDs.
	 * @param mixed $increment True - increment, false - decrement, integer - exact value.
	 * @return void
	 */
	public static function setCounter(array $id, $increment = true)
	{
		$id = implode(", ", $id);
		if (empty($id))
			return;
		$connection = \Bitrix\Main\Application::getInstance()->getConnection();
		$sql = intval($increment);
		if ($increment === true)
			$sql = "COUNTER+1";
		else if ($increment === false)
			$sql = "COUNTER-1";
		$connection->queryExecute("UPDATE ".self::getTableName()." SET COUNTER=".$sql." WHERE ID IN (".$id.")");
	}
}

class Question
{
	public static $storage = array();
}