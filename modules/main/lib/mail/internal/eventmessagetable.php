<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2025 Bitrix
 */

namespace Bitrix\Main\Mail\Internal;

use Bitrix\Main\Type;
use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\ArrayField;

/**
 * Class EventMessageTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_EventMessage_Query query()
 * @method static EO_EventMessage_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_EventMessage_Result getById($id)
 * @method static EO_EventMessage_Result getList(array $parameters = [])
 * @method static EO_EventMessage_Entity getEntity()
 * @method static \Bitrix\Main\Mail\Internal\EO_EventMessage createObject($setDefaultValues = true)
 * @method static \Bitrix\Main\Mail\Internal\EO_EventMessage_Collection createCollection()
 * @method static \Bitrix\Main\Mail\Internal\EO_EventMessage wakeUpObject($row)
 * @method static \Bitrix\Main\Mail\Internal\EO_EventMessage_Collection wakeUpCollection($rows)
 */
class EventMessageTable extends DataManager
{
	/**
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_event_message';
	}

	/**
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
			'TIMESTAMP_X' => array(
				'data_type' => 'datetime',
				'required' => true,
				'default_value' => function() {
					return new Type\DateTime();
				},
			),
			'EVENT_NAME' => array(
				'data_type' => 'string',
				'required' => true,
			),
			'LID' => array(
				'data_type' => 'string',
			),
			'ACTIVE' => array(
				'data_type' => 'string',
				'required' => true,
				'default_value' => 'Y'
			),
			'EMAIL_FROM' => array(
				'data_type' => 'string',
				'required' => true,
				'default_value' => '#EMAIL_FROM#'
			),
			'EMAIL_TO' => array(
				'data_type' => 'string',
				'required' => true,
				'default_value' => '#EMAIL_TO#'
			),
			'SUBJECT' => array(
				'data_type' => 'string',
			),
			'MESSAGE' => array(
				'data_type' => 'string',
			),
			'MESSAGE_PHP' => array(
				'data_type' => 'string',
			),
			'BODY_TYPE' => array(
				'data_type' => 'string',
				'required' => true,
				'default_value' => 'text'
			),
			'BCC' => array(
				'data_type' => 'string',
			),
			'REPLY_TO' => array(
				'data_type' => 'string',
			),
			'CC' => array(
				'data_type' => 'string',
			),
			'IN_REPLY_TO' => array(
				'data_type' => 'string',
			),
			'PRIORITY' => array(
				'data_type' => 'string',
			),
			'FIELD1_NAME' => array(
				'data_type' => 'string',
			),
			'FIELD1_VALUE' => array(
				'data_type' => 'string',
			),
			'FIELD2_NAME' => array(
				'data_type' => 'string',
			),
			'FIELD2_VALUE' => array(
				'data_type' => 'string',
			),
			'SITE_TEMPLATE_ID' => array(
				'data_type' => 'string',
			),
			(new ArrayField('ADDITIONAL_FIELD'))->configureSerializationPhp(),
			'EVENT_MESSAGE_SITE' => array(
				'data_type' => 'Bitrix\Main\Mail\Internal\EventMessageSite',
				'reference' => array('=this.ID' => 'ref.EVENT_MESSAGE_ID'),
			),
			'LANGUAGE_ID' => array(
				'data_type' => 'string',
			),
		);
	}

	public static function replaceTemplateToPhp($str, $fromTemplateToPhp=true)
	{
		preg_match_all("/#([0-9a-zA-Z_.]+?)#/", $str, $matchesFindPlaceHolders);
		$matchesFindPlaceHoldersCount = count($matchesFindPlaceHolders[1]);
		for($i=0; $i<$matchesFindPlaceHoldersCount; $i++)
			if(mb_strlen($matchesFindPlaceHolders[1][$i]) > 200)
				unset($matchesFindPlaceHolders[1][$i]);

		if(empty($matchesFindPlaceHolders[1]))
			return $str;
		$ar = $matchesFindPlaceHolders[1];

		$strResult = $str;
		$replaceTagsOne = array();

		if(!$fromTemplateToPhp)
		{
			foreach($ar as $k)
			{
				$replaceTo = '#'.$k.'#';

				$replaceFrom = '$arParams["'.$k.'"]';
				$replaceFromQuote = '$arParams[\''.$k.'\']';
				$replaceFromPhp = '<?='.$replaceFrom.';?>';

				$replaceTagsOne[$replaceFromPhp] = $replaceTo;
				$replaceTagsOne[$replaceFrom] = $replaceTo;
				$replaceTagsOne[$replaceFromQuote] = $replaceTo;
			}
		}
		else
		{
			$replaceTemplateString = '';
			foreach($ar as $k) $replaceTemplateString .= '|#'.$k.'#';

			$replaceTags = array();
			$openPhpTag = false;
			preg_match_all('/(<\?|\?>'.$replaceTemplateString.')/', $str, $matchesTag, PREG_OFFSET_CAPTURE);
			foreach($matchesTag[0] as $tag)
			{
				$placeHolder = $tag[0];
				$placeHolderPosition = $tag[1];
				$ch1 = mb_substr($placeHolder, 0, 1);
				$ch2 = mb_substr($placeHolder, 0, 2);

				if($ch2 == "<?")
					$openPhpTag = true;
				elseif($ch2 == "?>")
					$openPhpTag = false;
				elseif($ch1 == "#")
				{
					$placeHolderClear = mb_substr($placeHolder, 1, mb_strlen($placeHolder) - 2);

					$openQuote = (mb_substr($str, $placeHolderPosition - 2, 2) == '"{');
					$closeQuote = (mb_substr($str, $placeHolderPosition + mb_strlen($placeHolder), 2) == '}"');
					if($openPhpTag && $openQuote && $closeQuote)
						$replaceTo = '$arParams[\''.$placeHolderClear.'\']';
					else
						$replaceTo = '$arParams["'.$placeHolderClear.'"]';

					if(!$openPhpTag) $replaceTo = '<?=' . $replaceTo . ';?>';
					$replaceTags[$tag[0]][] = $replaceTo;
				}
			}

			foreach($replaceTags as $k => $v)
			{
				if(count($v)>1)
				{
					foreach($v as $replaceTo)
					{
						$resultReplace = preg_replace('/'.$k.'/', $replaceTo, $strResult, 1);
						if($resultReplace !== null)
							$strResult = $resultReplace;
					}
				}
				else
				{
					$replaceTagsOne[$k] = $v[0];
				}
			}
		}

		if(!empty($replaceTagsOne))
			$strResult = str_replace(array_keys($replaceTagsOne), array_values($replaceTagsOne), $strResult);

		// php parser delete newline following the closing tag in string passed to eval
		$strResult = str_replace(array("?>\n", "?>\r\n"), array("?>\n\n", "?>\r\n\r\n"), $strResult);

		return $strResult;
	}

	/**
	 * @param ORM\Event $event
	 * @return ORM\EventResult
	 */
	public static function onBeforeUpdate(ORM\Event $event)
	{
		$result = new ORM\EventResult();
		$data = $event->getParameters();

		if(array_key_exists('MESSAGE', $data['fields']))
		{
			$data['fields']['MESSAGE_PHP'] = static::replaceTemplateToPhp($data['fields']['MESSAGE']);
			$result->modifyFields($data['fields']);
		}

		return $result;
	}

	/**
	 * @param ORM\Event $event
	 * @return ORM\EventResult
	 */
	public static function onBeforeAdd(ORM\Event $event)
	{
		$result = new ORM\EventResult();
		$data = $event->getParameters();

		if(array_key_exists('MESSAGE', $data['fields']))
		{
			$data['fields']['MESSAGE_PHP'] = static::replaceTemplateToPhp($data['fields']['MESSAGE']);
			$result->modifyFields($data['fields']);
		}

		return $result;
	}
}
