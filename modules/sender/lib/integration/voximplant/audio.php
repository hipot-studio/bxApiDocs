<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage sender
 * @copyright 2001-2017 Bitrix
 */

namespace Bitrix\Sender\Integration\VoxImplant;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Sender\Preset\Templates\AudioCall;

/**
 * Class Audio
 * @package Bitrix\Sender\Integration\VoxImplant
 */
class Audio
{
	const AUDIO_TYPE_PRESET = 'preset';
	const AUDIO_TYPE_FILE = 'file';

	/** @var string $fileId File name. */
	private $fileId = '';

	/** @var string $presetName Preset name. */
	private $presetName = '';

	/** @var string $messageCode Message code. */
	private $messageCode = '';

	private $duration;
	/**
	 * Create instance.
	 *
	 * @return static
	 */
	public static function create()
	{
		return new static();
	}

	/**
	 * SpeechRate constructor.
	 */
	public function __construct()
	{
	}

	/**
	 * With value.
	 *
	 * @param string $value Value from user interface.
	 * @return $this
	 */
	public function withValue($value)
	{
		if ($value)
		{
			if (intval($value) > 0)
			{
				$this->withFile($value);
			}
			else
			{
				$this->withPreset($value);
			}
		}
		return $this;
	}

	/**
	 * With json value.
	 *
	 * @param string $json Value as json string from DB.
	 * @return $this
	 */
	public function withJsonString($json)
	{
		if($json <> '')
		{
			try
			{
				$params = Json::decode($json);
				if($params['type'] == self::AUDIO_TYPE_PRESET)
				{
					$this->withPreset($params['preset']);
				}
				if($params['type'] == self::AUDIO_TYPE_FILE)
				{
					$this->withFile($params['fileId']);
				}
				if($params['duration'])
				{
					$this->duration = $params['duration'];
				}
			}
			catch(ArgumentException $e)
			{
			}
		}
		return $this;
	}

	/**
	 * With message code.
	 *
	 * @param string $messageCode Message code.
	 * @return $this
	 */
	public function withMessageCode($messageCode = null)
	{

		$this->messageCode = $messageCode;
		return $this;
	}

	/**
	 * With file.
	 *
	 * @param string $fileId File id.
	 * @return $this
	 */
	public function withFile($fileId = null)
	{

		$this->fileId = $fileId;
		return $this;
	}

	/**
	 * With preset.
	 *
	 * @param string $presetName Preset code.
	 * @return $this
	 */
	public function withPreset($presetName = null)
	{
		$this->presetName = $presetName;
		return $this;
	}

	/**
	 * Does audio created from a preset
	 *
	 * @return bool
	 */
	public function createdFromPreset()
	{
		return !!$this->presetName;
	}

	/**
	 * Get audio file url
	 * @param bool $useAbsoluteUrl Force using absolute url.
	 * @return bool|string|null
	 * @throws \Bitrix\Main\ArgumentNullException
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public function getFileUrl($useAbsoluteUrl = false)
	{
		if ($this->createdFromPreset())
		{
			$url = AudioCall::getAudioFileUrlByCode($this->getPresetCode());
		}
		else
		{
			$url = \CFile::GetPath($this->getFileId());
			if ($url && $useAbsoluteUrl && !$this->isRemoteFile($url))
			{
				$urlManager = \Bitrix\Main\Engine\UrlManager::getInstance();
				$url = $urlManager->getHostUrl() . $url;
			}
		}

		return $url;
	}

	/**
	 * Get value for db (as json string)
	 * @return bool|string
	 * @throws ArgumentException
	 */
	public function getDbValue()
	{
		if (!$this->getFileId() && !$this->getPreset())
		{
			return false;
		}
		$result = [
			'type' => $this->createdFromPreset() ? self::AUDIO_TYPE_PRESET : self::AUDIO_TYPE_FILE,
			'duration' => $this->getDuration()
		];
		if ($this->createdFromPreset())
		{
			$result['preset'] = $this->getPreset();
		}
		else
		{
			$result['fileId'] = $this->getFileId();
		}
		return Json::encode($result);
	}

	/**
	 * Get default audio preset url for player.
	 * @return string
	 */
	public function getDefaultFileUrl()
	{
		$code = AudioCall::getDefaultCode();
		return AudioCall::getAudioFileUrlByCode($code);
	}

	/**
	 * Get file id
	 * @return string
	 */
	public function getFileId()
	{
		return $this->fileId;
	}

	/**
	 * Get preset code
	 * @return string
	 */
	public function getPreset()
	{
		return $this->presetName;
	}

	/**
	 * Get message code
	 * @return string
	 */
	public function getMessageCode()
	{
		return $this->messageCode;
	}

	/**
	 * get duration of current audio
	 * @return integer
	 */
	public function getDuration()
	{
		if (!$this->duration)
		{
			if ($this->createdFromPreset())
			{
				$this->duration = $this->getPresetFileDuration($this->getPresetCode());
			}
			else
			{
				$this->duration = $this->getMp3fileDuration($this->getFileId());
			}
		}
		return $this->duration;
	}

	/**
	 * Get preset audio duration
	 * @param string $presetCode Preset code.
	 * @return bool
	 */
	protected function getPresetFileDuration($presetCode)
	{
		return AudioCall::getDurationByCode($presetCode);
	}

	/**
	 * Get preset code without message code
	 * @return string
	 */
	private function getPresetCode()
	{
		return mb_strpos($this->getPreset(), $this->getMessageCode()) === 0?
			mb_substr($this->getPreset(), mb_strlen($this->getMessageCode()) + 1) : $this->getPreset();
	}

	/**
	 * Get mp3 file duration
	 * @param int $fileId File id.
	 * @return float|false
	 */
	protected function getMp3fileDuration($fileId)
	{
		if (!$fileId)
			return false;

		$fileName = \CFile::GetPath($fileId);
		$tmpFileName = null;

		if ($this->isRemoteFile($fileName))
		{
			$tmpFileName = \CFile::GetTempName('', 'tmpfile.mp3');
			$request = new HttpClient([
				"socketTimeout" => 5,
				"streamTimeout" => 5
			]);
			if (!$request->download($fileName, $tmpFileName))
			{
				$this->deleteTempFile($tmpFileName);

				return false;
			}

			$fileName = $tmpFileName;
		}
		else
		{
			$fileName = Application::getDocumentRoot() . $fileName;
		}

		if (!is_file($fileName) || !is_readable($fileName))
		{
			$this->deleteTempFile($tmpFileName);

			return false;
		}

		$file = fopen($fileName, "rb");
		if (!$file)
		{
			$this->deleteTempFile($tmpFileName);

			return false;
		}

		$duration = 0;
		$header = fread($file, 100);
		$offset = $this->getId3v2TagLength($header);
		fseek($file, $offset, SEEK_SET);
		while (!feof($file))
		{
			$frame = fread($file, 10);
			if (strlen($frame) < 10)
			{
				break;
			}
			else
			{
				// if 1111 1111 111x xxxx bits (header sequence) was found
				if ("\xff" === $frame[0] && (ord($frame[1]) & 0xe0) === 0xe0)
				{
					list($frameLength, $frameDuration) = $this->getFrameInfo(substr($frame, 0, 4));
					if (!$frameLength)
					{
						break;
					}
					$offset = $frameLength - 10;
					$duration += $frameDuration;
				}
				else
				{
					$offset = ('TAG' == substr($frame, 0, 3)) ? 118 : -9;
				}
				fseek($file, $offset, SEEK_CUR);
			}
		}
		fclose($file);
		$this->deleteTempFile($tmpFileName);

		return round($duration);
	}

	/**
	 * Delete temporary file created for duration parsing
	 * @param string|null $fileName Temporary file name.
	 * @return void
	 */
	private function deleteTempFile(?string $fileName): void
	{
		if ($fileName !== null && file_exists($fileName))
		{
			unlink($fileName);
		}
	}

	/**
	 * Get length of Id3v2 tag
	 * @param string $header Header.
	 * @return int
	 */
	private function getId3v2TagLength($header)
	{
		if (strlen($header) >= 10 && "ID3" == substr($header, 0, 3))
		{
			$hasExtendedHeader = (ord($header[5]) & 0x10) ? 1 : 0;
			$lengthByte1 = ord($header[6]);
			$lengthByte2 = ord($header[7]);
			$lengthByte3 = ord($header[8]);
			$lengthByte4 = ord($header[9]);
			if (!($lengthByte1 & 0x80) && !($lengthByte2 & 0x80) && !($lengthByte3 & 0x80) && !($lengthByte4 & 0x80))
			{
				$tagHeaderLength = 10 + ($hasExtendedHeader ? 10 : 0);
				$tagContentLength =
					(($lengthByte1 & 0x7f) << 21) +
					(($lengthByte2 & 0x7f) << 14) +
					(($lengthByte3 & 0x7f) << 7) +
					($lengthByte4 & 0x7f);

				return $tagHeaderLength + $tagContentLength;
			}
		}
		return 0;
	}

	/**
	 * Get frame info
	 * @param string $frame Frame.
	 * @return array
	 */
	private function getFrameInfo($frame)
	{
		$versions = [0 => '2.5', 2 => '2', 3 => '1'];
		$layers = [1 => '3', 2 => '2', 3 => '1'];
		$bitrates = [
			1 => [
				1 => [0, 32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448],
				2 => [0, 32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384],
				3 => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320]
			],
			2 => [
				1 => [0, 32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256],
				2 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
				3 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
			]
		];
		$sampleRates = [
			'1' => [44100, 48000, 32000],
			'2' => [22050, 24000, 16000],
			'2.5' => [11025, 12000, 8000]
		];
		$samples = [
			1 => [1 => 384, 2 => 1152, 3 => 1152],
			2 => [1 => 384, 2 => 1152, 3 => 576]
		];

		$layerData = ord($frame[1] ?? "\x00");
		$rateData = ord($frame[2] ?? "\x00");

		$version = $versions[($layerData & 0x18) >> 3] ?? null;
		$layer = $layers[($layerData & 0x06) >> 1] ?? null;
		if ($version === null || $layer === null)
		{
			return [0, 0];
		}

		$bitrateVersion = ($version === '2.5' ? 2 : $version);

		$bitrateIndex = ($rateData & 0xf0) >> 4;
		$bitrate = $bitrates[$bitrateVersion][$layer][$bitrateIndex] ?? null;

		$sampleRateIndex = ($rateData & 0x0c) >> 2;//0xc => b1100
		$sampleRate = $sampleRates[$version][$sampleRateIndex] ?? null;
		$padding = ($rateData & 0x02) >> 1;

		if ($bitrate === null || $sampleRate === null)
		{
			return [0, 0];
		}

		// Free format declares no bitrate, so the frame length cannot be taken from the header.
		if ($bitrate === 0)
		{
			return [0, 0];
		}

		$duration = $samples[$bitrateVersion][$layer] / $sampleRate;

		if ($layer == 1)
		{
			$frameLength = intval(((12 * $bitrate * 1000 / $sampleRate) + $padding) * 4);
		}
		else
		{
			// The length follows the samples per frame: a Layer III frame of MPEG-2 and MPEG-2.5 holds half
			// the samples of an MPEG-1 one, so the MPEG-1 factor 144 (1152 / 8) would skip every other frame.
			$frameLength = intval(($samples[$bitrateVersion][$layer] / 8 * $bitrate * 1000 / $sampleRate) + $padding);
		}

		return [$frameLength, $duration];
	}

	/**
	 * Is $fileName an url?
	 * @param string $fileName Filename or url.
	 * @return bool
	 */
	private function isRemoteFile($fileName)
	{
		return preg_match('/^(https?):\/\/.*/', $fileName) === 1;
	}
}