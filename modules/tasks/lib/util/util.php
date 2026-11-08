<?php
/**
 * Namespace contains functions\classes for different purposes
 * See also
 *      tasks/classes/general/tasktools.php
 *      tasks/tools.php
 */

namespace Bitrix\Tasks\Util;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

class Util
{
	public static function trim($arg)
	{
		$arg = (string) $arg;
		if($arg == '')
		{
			return $arg;
		}

		$arg = trim($arg);
		// remove that annoying undying sequences from wysiwyg
		$pattern = '/(^((\x20)?(\xc2)?\xa0(\x20)?)+|((\x20)?(\xc2)?\xa0(\x20)?)+$)/';
		if (LANG_CHARSET == 'UTF-8')
		{
			$pattern .= 'u';
		}
		$arg = preg_replace($pattern, '', $arg);

		return $arg;
	}

	public static function escape($arg)
	{
		if(is_array($arg))
		{
			foreach($arg as $i => $value)
			{
				$arg[$i] = static::escape($value);
			}

			return $arg;
		}
		else
		{
			if(is_numeric($arg) && !is_string($arg))
			{
				return $arg;
			}
			else
			{
				return htmlspecialcharsbx($arg);
			}
		}
	}

	public static function hashCode($str): string
	{
		return base_convert(crc32($str), 16, 10);
	}

	public static function secureBackUrl($url)
	{
		return str_replace(array('//', '/\\'), '', (string) $url);
	}

	public static function replaceUrlParameters($url, array $paramsToAdd = array(), array $paramsToDelete = array(), array $options = array())
	{
		// replaceQueryParameters() does not like #placeholders# in url, so a little trick is needed
		$found = array();
		preg_match_all("/#([a-zA-Z0-9_-]+)#/", $url, $found);

		$match = array();
		if(is_array($found[1]) && !empty($found[1]))
		{
			foreach($found[1] as $holder)
			{
				$match['#'.$holder.'#'] = '__'.$holder.'__';
			}
		}

		if(!empty($match))
		{
			$url = str_replace(array_keys($match), $match, $url);
		}

		// to avoid adding duplicates and delete other params
		$url = static::replaceQueryParameters($url, $paramsToAdd, $paramsToDelete, $options);

		if(!empty($match))
		{
			$match = array_flip($match);
			$url = str_replace(array_keys($match), $match, $url);
		}

		return $url;
	}

	/**
	 * Drops query parameters whose names match keys of $paramsToAdd or values of
	 * $paramsToDelete, then appends $paramsToAdd to the end of the query.
	 *
	 * The query string is processed literally, without parsing it into arrays, so
	 * parameter names containing brackets or dots are matched as-is.
	 *
	 * @param string $url
	 * @param array $paramsToAdd
	 * @param array $paramsToDelete
	 * @param array $options 'encode' urlencodes name and value; 'skip_empty' drops empty values.
	 * @return string
	 */
	private static function replaceQueryParameters($url, array $paramsToAdd, array $paramsToDelete, array $options)
	{
		$paramsToRemove = array_merge(array_keys($paramsToAdd), $paramsToDelete);

		$urlParts = explode('?', $url, 2);
		if (count($urlParts) === 2 && $urlParts[1] !== '')
		{
			$pairs = explode('&', $urlParts[1]);
			foreach ($pairs as $i => $pair)
			{
				$nameValue = explode('=', $pair, 2);
				if (count($nameValue) === 2 && in_array($nameValue[0], $paramsToRemove))
				{
					unset($pairs[$i]);
				}
			}

			$url = empty($pairs) ? $urlParts[0] : $urlParts[0] . '?' . implode('&', $pairs);
		}

		$newParams = [];
		foreach ($paramsToAdd as $name => $value)
		{
			if (is_array($value))
			{
				continue;
			}
			if (!empty($options['skip_empty']) && (string)$value === '')
			{
				continue;
			}
			if (!empty($options['encode']))
			{
				$newParams[] = urlencode($name) . '=' . urlencode($value);
			}
			else
			{
				$newParams[] = $name . '=' . $value;
			}
		}

		if (!empty($newParams))
		{
			$separator = (mb_strpos($url, '?') === false ? '?' : '&');

			$fragmentPos = mb_strpos($url, '#');
			if ($fragmentPos === false)
			{
				$url = $url . $separator . implode('&', $newParams);
			}
			else
			{
				$url = mb_substr($url, 0, $fragmentPos) . $separator . implode('&', $newParams) . mb_substr($url, $fragmentPos);
			}
		}

		return $url;
	}

	public static function getParser(array $parameters = array())
	{
		$parser = \Bitrix\Tasks\Integration\Forum::getParser($parameters);
		if($parser == null)
		{
			$parser = \Bitrix\Tasks\Integration\SocialNetwork::getParser($parameters);
		}
		if($parser == null)
		{
			$parser = new \CTextParser();
		}

		if (isset($parameters["maxStringLen"]))
		{
			$parser->maxStringLen = intval($parameters["maxStringLen"]);
		}

		return $parser;
	}


	/**
	 * Generate v4 UUID
	 * Version 4 UUIDs are pseudo-random.
	 *
	 * @param bool $brackets
	 * @return string
	 *
	 * @use \Bitrix\Main\UuidGenerator::generateV4()
	 */
	public static function generateUUID($brackets = true)
	{
		if (class_exists('\Bitrix\Main\UuidGenerator'))
		{
			if ($brackets)
			{
				return '{' . \Bitrix\Main\UuidGenerator::generateV4() . '}';
			}

			return \Bitrix\Main\UuidGenerator::generateV4();
		}

		$uuid = '';

		if ($brackets)
		{
			$uuid .= '{';
		}

		$uuid .= sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			// 32 bits for "time_low"
			mt_rand(0, 0xffff), mt_rand(0, 0xffff),

			// 16 bits for "time_mid"
			mt_rand(0, 0xffff),

			// 16 bits for "time_hi_and_version",
			// four most significant bits holds version number 4
			mt_rand(0, 0x0fff) | 0x4000,

			// 16 bits, 8 bits for "clk_seq_hi_res",
			// 8 bits for "clk_seq_low",
			// two most significant bits holds zero and one for variant DCE1.1
			mt_rand(0, 0x3fff) | 0x8000,

			// 48 bits for "node"
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
		);

		if ($brackets)
		{
			$uuid .= '}';
		}

		return ($uuid);
	}

	public static function getServerTimeZoneOffset()
	{
		$localTime = new \DateTime();
		return $localTime->getOffset();
	}

	/**
	 * Gets portal create date in unix timestamp.
	 * @return string
	 */
	public static function getPortalCreateTimestamp()
	{
		return (int)Option::get(
			'main', '~controller_date_create'
		);
	}

	public static function log($info)
	{
		$handler = Application::getInstance()->getExceptionHandler();

		if(is_subclass_of($info, '\\Bitrix\\Main\\SystemException'))
		{
			$handler->writeToLog($info);
		}
		else
		{
			$handler->writeToLog(new \TasksException((string) $info));
		}
	}

	public static function setOption($name, $value)
	{
		Option::set('tasks', $name, $value);
	}

	public static function getOption($name, $default = '')
	{
		return Option::get('tasks', $name, $default);
	}

	public static function unSetOption($name)
	{
		Option::delete('tasks', array('name' => $name));
	}

	public static function printDebug($data)
	{
		if(function_exists('_dump_r'))
		{
			//_dump_r($data);
		}
	}

	public static function is_serialized($value, &$result = null)
	{
		// Bit of a give away this one
		if (!is_string($value))
		{
			return false;
		}
		// Serialized false, return true. unserialize() returns false on an
		// invalid string or it could return false if the string is serialized
		// false, eliminate that possibility.
		if ($value === 'b:0;')
		{
			$result = false;
			return true;
		}
		$length = mb_strlen($value);
		$end	= '';
		switch ($value[0])
		{
			case 's':
				if ($value[$length - 2] !== '"')
				{
					return false;
				}

			case 'b':
			case 'i':
			case 'd':
				// This looks odd but it is quicker than isset()ing
				$end .= ';';
			case 'a':
			case 'O':
				$end .= '}';
				if ($value[1] !== ':')
				{
					return false;
				}
				switch ($value[2])
				{
					case 0:
					case 1:
					case 2:
					case 3:
					case 4:
					case 5:
					case 6:
					case 7:
					case 8:
					case 9:
						break;
					default:
						return false;
				}
			case 'N':
				$end .= ';';
				if ($value[$length - 1] !== $end[0])
				{
					return false;
				}
				break;
			default:
				return false;
		}
		if (($result = @unserialize($value, ['allowed_classes' => false])) === false)
		{
			$result = null;
			return false;
		}
		return true;
	}
}
