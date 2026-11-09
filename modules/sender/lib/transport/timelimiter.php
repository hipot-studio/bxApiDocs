<?php


namespace Bitrix\Sender\Transport;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\UserTable;
use Bitrix\Sender\Entity\Letter;
use Bitrix\Sender\Message;

Loc::loadMessages(__FILE__);
class TimeLimiter implements iLimiter
{
	public const DEFAULT_SENDING_START = '09:00';
	public const DEFAULT_SENDING_END = '18:00';

	/** Server timestamp of the next opening of the window, present only while the window is closed. */
	public const PARAMETER_NEXT_OPENING = 'nextOpening';
	/**
	 * @var Message\iBase
	 */
	private $letter;
	private array $parameters = [];

	/**
	 * Hit-scoped memory: the window is checked on every recipient of the letter, while the zone of the
	 * author, its offset and the window itself stay the same for the whole sending.
	 *
	 * @var array<int, string> author id => their own time zone, empty when they have none
	 */
	private static array $zoneByAuthor = [];
	/** @var array<string, int> time zone => offset from the server one */
	private static array $offsetByZone = [];
	/** @var array<string, SendingWindow> */
	private static array $windows = [];

	public function withLetter($letter)
	{
		$this->letter = $letter;
		$this->parameters = [];

		return $this;
	}

	/**
	 * Create instance.
	 * @return static
	 */
	public static function create()
	{
		return new static();
	}

	/**
	 * @inheritDoc
	 */
	public function getLimit()
	{
		return 1;
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrent()
	{
		unset($this->parameters[self::PARAMETER_NEXT_OPENING]);

		if (!$this->letter)
		{
			return 0;
		}

		$configuration = $this->letter->getConfiguration();
		$sendingStart = $configuration->get('SENDING_START');
		$sendingEnd = $configuration->get('SENDING_END');
		$sendingTimeEnabled = $configuration->get('SENDING_TIME');

		if (!$sendingEnd || !$sendingStart || $sendingTimeEnabled !== 'Y')
		{
			return 0;
		}

		$window = self::window(
			(string)$sendingStart,
			(string)$sendingEnd,
			$this->resolveOwnerOffset($configuration)
		);
		$now = (new DateTime())->getTimestamp();

		if ($window->isOpenAt($now))
		{
			return 0;
		}

		$this->setParameter(self::PARAMETER_NEXT_OPENING, $window->nextOpeningAt($now));

		return 1;
	}

	/**
	 * Offset of the window owner: an empty zone of the author falls back to the portal one inside
	 * \CTimeZone::getTimezoneOffset(). \CTimeZone::GetOffset() does not fit: without an argument it takes
	 * the zone of an arbitrary visitor of the hit that runs the sending, and with the author id it returns
	 * the stale TIME_ZONE_OFFSET instead of that fallback.
	 */
	private function resolveOwnerOffset(Message\Configuration $configuration): int
	{
		if (!\CTimeZone::OptionEnabled())
		{
			return 0;
		}

		$zone = $this->resolveZoneFrom($configuration);

		return self::$offsetByZone[$zone] ??= \CTimeZone::getTimezoneOffset($zone);
	}

	/**
	 * The letter publishes the zone of its author whenever the row it came from carries it — the list of
	 * mailings selects it through the join it already needs for the author name. Without that key the
	 * limiter looks the author up itself: background sending works with one letter per hit, so there it
	 * is a single query and not a per-row one. A letter without a saved author has no owner, and the portal
	 * zone applies.
	 */
	private function resolveZoneFrom(Message\Configuration $configuration): string
	{
		$published = $configuration->get('LETTER_CREATED_BY_TIME_ZONE');

		if ($published !== null)
		{
			return (string)$published;
		}

		return $this->resolveOwnerZone((int)$configuration->get('LETTER_OWNER_ID'));
	}

	/**
	 * An author without a zone is remembered as well, otherwise the row missing from b_user is looked up
	 * again on every recipient.
	 */
	private function resolveOwnerZone(int $authorId): string
	{
		if ($authorId <= 0)
		{
			return '';
		}

		if (!isset(self::$zoneByAuthor[$authorId]))
		{
			$author = UserTable::getRow([
				'select' => ['TIME_ZONE'],
				'filter' => ['=ID' => $authorId],
			]);
			self::$zoneByAuthor[$authorId] = (string)($author['TIME_ZONE'] ?? '');
		}

		return self::$zoneByAuthor[$authorId];
	}

	private static function window(string $startText, string $endText, int $offsetSeconds): SendingWindow
	{
		$key = $startText . '|' . $endText . '|' . $offsetSeconds;

		return self::$windows[$key] ??= new SendingWindow($startText, $endText, $offsetSeconds);
	}

	/**
	 * @inheritDoc
	 */
	public function getUnitName()
	{
		return '';
	}

	/**
	 * @inheritDoc
	 */
	public function getUnit()
	{
		return '';
	}

	/**
	 * @inheritDoc
	 */
	public function getCaption()
	{
		return '';
	}

	/**
	 * @inheritDoc
	 */
	public function getParameter($name)
	{
		return $this->parameters[$name] ?? null;
	}

	/**
	 * @inheritDoc
	 */
	public function setParameter($name, $value)
	{
		$this->parameters[$name] = $value;
	}

	/**
	 * @inheritDoc
	 */
	public function isHidden()
	{
		return true;
	}

	/**
	 * add options SENDING_START and SENDING_END to the Message Configuration
	 * @param $configuration
	 */
	public static function prepareMessageConfiguration($configuration)
	{
		$configuration->addOption(new Message\ConfigurationOption([
			'type' => Message\ConfigurationOption::TYPE_CHECKBOX,
			'code' => 'SENDING_TIME',
			'group' => Message\ConfigurationOption::GROUP_ADDITIONAL,
			'name' => Loc::getMessage('SENDER_INTEGRATION_MESSAGE_CONFIG_SENDING_TIME'),
			'show_in_list' => false,
			'required' => false,
			'value' => Option::get('sender', 'sending_time')
		]));

		$configuration->addOption(new Message\ConfigurationOption([
			'type' => Message\ConfigurationOption::TYPE_STRING,
			'code' => 'SENDING_START',
			'name' => Loc::getMessage('SENDER_INTEGRATION_MESSAGE_CONFIG_SENDING_START'),
			'group' => Message\ConfigurationOption::GROUP_ADDITIONAL,
			'show_in_list' => false,
			'required' => false,
			'value' => Option::get('sender', 'sending_start', TimeLimiter::DEFAULT_SENDING_START)
		]));

		$configuration->addOption(new Message\ConfigurationOption([
			'type' => Message\ConfigurationOption::TYPE_STRING,
			'code' => 'SENDING_END',
			'name' => Loc::getMessage('SENDER_INTEGRATION_MESSAGE_CONFIG_SENDING_END'),
			'group' => Message\ConfigurationOption::GROUP_ADDITIONAL,
			'show_in_list' => false,
			'required' => false,
			'value' => Option::get('sender', 'sending_end', TimeLimiter::DEFAULT_SENDING_END)
		]));
	}

	/**
	 * add options view SENDING_START and SENDING_END to the Message Configuration
	 * @param $configuration
	 */
	public static function prepareMessageConfigurationView($configuration)
	{

		$sendingStart = $configuration->getOption('SENDING_START');
		$sendingEnd = $configuration->getOption('SENDING_END');
		$checkbox = $configuration->getOption('SENDING_TIME');

		$view = function($input, $checkbox)
		{
			$prefix = 'CONFIGURATION_';
			$inputCode = htmlspecialcharsbx($prefix.$input->getCode());
			$checkboxCode = htmlspecialcharsbx($prefix.$checkbox->getCode());
			ob_start();
			Extension::load("sender.secret_block");
			$inputHtml = "<select 
				id=\"$inputCode\"
				name=\"$inputCode\"
				value='".$input->getValue()."'
				class=\"bx-sender-form-control bx-sender-message-editor-field-select\">";
			for ($hour = 0; $hour < 24; $hour++)
			{
				foreach ([0, 30] as $minute)
				{
					$time = strtotime(sprintf("%02d:%02d", $hour, $minute));
					$formatted = (new \DateTime())
						->setTimestamp($time)
						->format(Context::getCurrent()
							->getCulture()
							->getShortTimeFormat()
						);

					$inputHtml .= "<option value='{$formatted}'";
					$inputHtml .= $time === strtotime($input->getValue()) ? "selected" : "";
					$inputHtml .= ">{$formatted}</option>";
				}
			}

			$inputHtml .= '</select>';

			echo $inputHtml;
			$params = \Bitrix\Main\Web\Json::encode(
				[
					'elementId' => $inputCode,
					'conditionElementId' => $checkboxCode
				]
			);

			echo "<script>new BX.Sender.SecretBlock(".$params.")</script>";

			return ob_get_clean();
		};

		$sendingStart->setView($view($sendingStart, $checkbox));
		$sendingEnd->setView($view($sendingEnd, $checkbox));
	}
}