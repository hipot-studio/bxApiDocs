<?php

/**
 * Class LPA
 *
 * Limited PHP Access.
 */
class LPA
{
	public static function PrepareContent($filesrc)
	{
		$arPHP = PHPParser::ParseFile($filesrc);
		$l = count($arPHP);
		if ($l > 0)
		{
			$new_filesrc = '';
			$end = 0;
			$php_count = 0;
			for ($n = 0; $n < $l; $n++)
			{
				$start = $arPHP[$n][0];
				$new_filesrc .= mb_substr($filesrc, $end, $start - $end);
				$end = $arPHP[$n][1];

				//Trim php tags
				$src = static::trimPhpTags($arPHP[$n][2]);

				//If it's Component 2, keep the php code. If it's component 1 or ordinary PHP - than replace code by #PHPXXXX# (XXXX - count of PHP scripts)
				if (static::isComponentCall($src))
				{
					$new_filesrc .= $arPHP[$n][2];
				}
				else
				{
					$new_filesrc .= '#PHP' . str_pad(++$php_count, 4, "0", STR_PAD_LEFT) . '#';
				}
			}
			$new_filesrc .= mb_substr($filesrc, $end);
			$filesrc = $new_filesrc;
		}

		return $filesrc;
	}

	public static function Process($filesrc = false, $old_filesrc = false)
	{
		if ($filesrc === false)
		{
			return '';
		}

		// Find all php fragments in $filesrc and:
		// 	1. Kill all non-component 2.0 fragments
		// 	2. Get and check params of components
		$arPHP = PHPParser::ParseFile($filesrc);
		$l = count($arPHP);
		if ($l > 0)
		{
			$new_filesrc = '';
			$end = 0;
			for ($n = 0; $n < $l; $n++)
			{
				$start = $arPHP[$n][0];
				$new_filesrc .= self::EncodePHPTags(mb_substr($filesrc, $end, $start - $end));
				$end = $arPHP[$n][1];

				//Trim php tags
				$src = static::trimPhpTags($arPHP[$n][2]);

				//If it's Component 2 - we handle it's params, non components2 will be somehow erased
				$component2FunctionName = '';

				if (static::isComponentCall($src, $component2FunctionName))
				{
					$arRes = PHPParser::CheckForComponent2('<?' . $src . '?>');
					if ($arRes)
					{
						$comp_name = $arRes['COMPONENT_NAME'];
						$template_name = $arRes['TEMPLATE_NAME'];
						$arParams = $arRes['PARAMS'];
						$arPHPparams = [];
						self::ComponentChecker($arParams, $arPHPparams);
						$len = count($arPHPparams);
						$br = "\r\n";
						$code = $component2FunctionName . '(' . $br .
							"\t" . '"' . EscapePHPString($comp_name) . '",' . $br .
							"\t" . '"' . EscapePHPString($template_name) . '",' . $br;
						// If exist at least one parameter with php code inside
						if (!empty($arParams))
						{
							// Get array with description of component params
							$arCompParams = CComponentUtil::GetComponentProps($comp_name);
							$arTemplParams = CComponentUtil::GetTemplateProps($comp_name, $template_name);

							$arParameters = [];
							if (isset($arCompParams["PARAMETERS"]) && is_array($arCompParams["PARAMETERS"]))
							{
								$arParameters = $arParameters + $arCompParams["PARAMETERS"];
							}
							if (is_array($arTemplParams))
							{
								$arParameters = $arParameters + $arTemplParams;
							}

							// Replace values from 'DEFAULT'
							for ($e = 0; $e < $len; $e++)
							{
								$par_name = $arPHPparams[$e];
								$arParams[$par_name] = $arParameters[$par_name]['DEFAULT'] ?? '';
							}

							//ReturnPHPStr
							$params = PHPParser::ReturnPHPStr2($arParams, $arParameters);
							$code .= "\t" . 'array(' . $br . "\t" . $params . $br . "\t" . ')';
						}
						else
						{
							$code .= "\t" . 'array()';
						}
						$parent_comp = $arRes['PARENT_COMP'];
						$arExParams_ = $arRes['FUNCTION_PARAMS'];

						$bEx = isset($arExParams_) && is_array($arExParams_) && !empty($arExParams_);

						if (!$parent_comp || mb_strtolower($parent_comp) == 'false')
						{
							$parent_comp = false;
						}
						if ($parent_comp)
						{
							if ($parent_comp == 'true' || is_numeric($parent_comp))
							{
								$code .= ',' . $br . "\t" . $parent_comp;
							}
							else
							{
								$code .= ',' . $br . "\t\"" . EscapePHPString($parent_comp) . '"';
							}
						}
						if ($bEx)
						{
							if (!$parent_comp)
							{
								$code .= ',' . $br . "\tfalse";
							}

							$arExParams = [];
							foreach ($arExParams_ as $k => $v)
							{
								$k = CMain::_ReplaceNonLatin($k);
								$v = CMain::_ReplaceNonLatin($v);
								if ($k <> '' && $v <> '')
								{
									$arExParams[$k] = $v;
								}
							}
							$exParams = PHPParser::ReturnPHPStr2($arExParams);
							$code .= ',' . $br . "\tarray(" . $exParams . ')';
						}
						$code .= $br . ');';
						$code = '<?' . $code . '?>';
						$new_filesrc .= $code;
					}
					else
					{
						$new_filesrc .= '<??>';
					}
				}
				else
				{
					$new_filesrc .= '<??>';
				}
			}
			$new_filesrc .= self::EncodePHPTags(mb_substr($filesrc, $end));
			$filesrc = $new_filesrc;
		}
		else
		{
			$filesrc = self::EncodePHPTags($filesrc);
		}

		if (str_contains($filesrc, '#PHP') && $old_filesrc !== false) // We have to handle php fragments
		{
			// Get array of PHP scripts from old saved file
			$arPHP = PHPParser::ParseFile($old_filesrc);
			$arPHPscripts = [];
			$l = count($arPHP);
			if ($l > 0)
			{
				for ($n = 0; $n < $l; $n++)
				{
					$src = static::trimPhpTags($arPHP[$n][2]);

					if (!static::isComponentCall($src))
					{
						$arPHPscripts[] = $src;
					}
				}
			}

			// Ok, so we already have array of PHP scripts lets check our new content
			// LPA-users CAN delete PHP fragments and swap them but CAN'T add new or modify existent:
			while (preg_match('/#PHP\d{4}#/iu', $filesrc, $res))
			{
				$php_begin = mb_strpos($filesrc, $res[0]);
				$php_fr_num = intval(mb_substr($filesrc, $php_begin + 4, 4)) - 1; // Number of PHP fragment from #PHPXXXX# conctruction

				if (isset($arPHPscripts[$php_fr_num]))
				{
					$code = '<?' . $arPHPscripts[$php_fr_num] . '?>';
				}
				else
				{
					$code = '<??>';
				}
				$filesrc = mb_substr($filesrc, 0, $php_begin) . $code . mb_substr($filesrc, $php_begin + 9);
			}
		}

		return $filesrc;
	}

	public static function EncodePHPTags($str)
	{
		$str = str_replace(["<?", "?>", "<%", "%>"], ["&lt;?", "?&gt;", "&lt;%", "%&gt;"], $str);

		static $pattern = "/(<script[^>]*language\\s*=\\s*)('|\"|)php('|\"|)([^>]*>)/i";
		$str = preg_replace($pattern, "&lt;??&gt;", $str);

		return $str;
	}

	public static function ComponentChecker(&$arParams, &$arPHPparams, $parentParamName = false)
	{
		//all php fragments wraped by ={}
		foreach ($arParams as $param_name => $paramval)
		{
			if (str_starts_with($param_name, '={') && str_ends_with($param_name, '}'))
			{
				$key = substr($param_name, 2, -1);
				if ($key !== strval(intval($key)))
				{
					unset($arParams[$param_name]);
					continue;
				}
			}
			if (is_array($paramval))
			{
				self::ComponentChecker($paramval, $arPHPparams, ($parentParamName !== false ? $parentParamName : $param_name));
				$arParams[$param_name] = $paramval;
			}
			elseif (str_starts_with($paramval, '={') && str_ends_with($paramval, '}'))
			{
				$arPHPparams[] = ($parentParamName !== false ? $parentParamName : $param_name);
			}
		}
	}

	protected static function trimPhpTags(string $src): string
	{
		if (str_starts_with($src, '<?php'))
		{
			$src = substr($src, 5);
		}
		elseif (str_starts_with($src, '<?'))
		{
			$src = substr($src, 2);
		}

		if (str_ends_with($src, '?>'))
		{
			$src = substr($src, 0, -2);
		}

		return $src;
	}

	protected static function isComponentCall(string $src, string &$functionName = ''): bool
	{
		$src = ltrim($src);

		$functionName = '';
		foreach (PHPParser::getComponentFunctionStrings() as $function)
		{
			$componentBegin = mb_strtoupper($function) . '(';
			if (mb_strtoupper(mb_substr($src, 0, mb_strlen($componentBegin))) == $componentBegin)
			{
				$functionName = $function;
				return true;
			}
		}

		return false;
	}
}
