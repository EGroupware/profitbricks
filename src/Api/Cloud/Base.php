<?php
/**
 * EGroupware - IONOS Cloud API base class
 *
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb-AT-egroupware.org>
 * @package profitbricks
 * @copyright (c) 2017-23 by Ralf Becker <rb-AT-egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Profitbricks\Api\Cloud;

use EGroupware\Api;

/**
 * Base class for IONOS Cloud API
 */
abstract class Base implements \JsonSerializable
{
	const APP = 'profitbricks';

	const CLOUD_API = 'https://api.ionos.com/cloudapi/v6/';

	/**
	 * URL relative to CLOUD_API
	 */
	const BASE = '';

	/**
	 * Unique attribute, which can be used as value in get instead of the UUID
	 */
    const UNIQ_ATTR = null;

	/**
	 * @var string UUID of item
	 */
	protected string $id;
	/**
	 * @var string type of item
	 */
	protected string $type;
	/**
	 * @var string URL of item
	 */
	protected string $href;

	/**
	 * @var array with values for keys "etag", "createdDate", ...
	 */
    protected array $metadata;

	/**
	 * @var array name => type pairs
	 */
	static protected array $properties = [];
	/**
	 * @var array name => value pairs
	 */
	static protected array $defaults = [];

 	protected function __construct(array $attrs, bool $check=false)
	{
		foreach($attrs+static::$defaults as $name => $value)
		{
			if ($name === 'properties')
			{
                if ($check)
                {
                    static::checkProperties($value);
                }
				foreach($value as $n => $v)
				{
                    if ($check && !property_exists($this, $n))
					{
						throw new \InvalidArgumentException("Unknown property '$name/$n'!");
					}
					$this->$n = $v;
				}
				continue;
			}
			if ($check && !property_exists($this, $name))
			{
				throw new \InvalidArgumentException("Unknown property '$name'!");
			}
			$this->$name = $value;
		}
	}

	/**
	 * Check properties are defined and of correct type
	 *
	 * @param array $attrs
	 * @throws \InvalidArgumentException for unknown or wrong typed properties
	 */
	protected static function checkProperties(array $attrs)
	{
		foreach($attrs as $name => $value)
		{
			if (!isset(static::$properties[$name]))
			{
				throw new \InvalidArgumentException("Unknown property '$name'!");
			}
			switch($type=static::$properties[$name])
			{
				default:
					$null_ok = $type[0] === '?';
					$check = 'is_'.substr($type, (int)$null_ok);
					if (!$check($value) && !($null_ok && !isset($value)))
					{
						throw new \InvalidArgumentException("Property '$name' is NO $type, got a ".gettype($value)."!");
					}
					break;
			}
		}
	}

	/**
	 * Return properties used e.g. json-serialized as body of PUT or POST requests
	 *
	 * @return array[]
	 */
	public function jsonSerialize(): array
	{
		$properties = [];
		foreach(static::$properties as $name => $type)
		{
            // set all non-null properties, plus the required ones ($type[0] !== '?')
            if (isset($this->$name) || $type[0] !== '?')
            {
                $properties[$name] = $this->$name;
            }
		}
		return ['properties' => $properties];
	}

	/**
	 * List all objects
	 *
	 * @param int $depth
	 * @param int $offset
	 * @param int $limit
	 * @return static[]
	 * @throws Api\Exception\NotFound
	 */
	static function index(int $depth=1, int $offset=0, int $limit=100) : array
	{
		$items = [];
		foreach(self::call(static::BASE, [
			'depth' => $depth,
			'offset' => $offset,
			'limit' => $limit,
		]) as $item)
		{
			$items[] = new static($item);
		}
		return $items;
	}

	/**
	 * Get item specified by its Id or another uniq attribute
     *
	 * @param string $id
	 * @param int $depth
	 * @return static
	 * @throws Api\Exception\NotFound
	 */
	static function get(string $id, int $depth=1) : static
	{
		if (!self::isUuid($id))
		{
            if (!empty($id) && !empty($attr=static::UNIQ_ATTR))
            {
	            $offset = 0;
	            $limit = 100;
	            do
	            {
		            foreach ($items = static::index($depth, $offset, $limit) as $item)
		            {
			            if ($item->$attr === $id)
			            {
				            return $item;
			            }
		            }
		            $offset += $limit;
	            } while (count($items) === $limit);
            }
			throw new Api\Exception\NotFound("Invalid value for id: '$id' --> NOT found!");
		}
		return new static(self::call(static::BASE.'/'.$id));
	}

	/**
	 * Delete item
	 *
	 * @return void
	 */
	public function delete()
	{
		if (empty($this->id) || !self::call(static::BASE.'/'.$this->id, [], [], 'DELETE'))
		{
			throw new Api\Exception\NotFound("Item with id '$this->id' not found!");
		}
	}

	/**
	 * Add an item
	 * @param array $attrs
	 * @return static
	 * @throws Api\Exception\NotFound
	 */
	static function add(array $attrs) : static
	{
		// check attributes exist
		$item = new static($attrs, true);

		$data = self::call(static::BASE, [], [], 'POST', $item);

		$item->__construct($data);
		return $item;
	}

	/**
	 * Update the item
	 *
	 * @param array $attrs
	 * @return bool|resource
	 */
	public function update(array $attrs)
	{
		// check attributes
		$this->__construct($attrs, true);

		return self::call(static::BASE.'/'.$this->id, [], $this);
	}

	/**
     * Make protected properties public readonly available
     *
	 * @param string $name
	 * @return void
	 */
	public function __get(string $name)
	{
        return $this->$name;
	}

	/**
	 * Check given string is a UUID
	 *
	 * @param string $str
	 * @return bool
	 */
	static function isUuid(string $str) : bool
	{
		return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $str);
	}

	/**
	 * Get items from API
	 *
	 * @param string $what eg. "datacenters" or "datacenters/$id/servers" or full URL eg. self::AUTH_API.'token/generate'
	 * @param array $get_params
	 * @param array $headers
	 * @param string $method "GET" (default), "POST", "PUT" or "DELETE"
	 * @param string|array|object $body
	 * @return array with items, for collection or just response data otherwise
	 * @throws Api\Exception\NotFound
	 */
	protected static function call(string $what, array $get_params=[], array $headers=[], $method='GET', $body='', float $timeout=2)
	{
		$url = (substr($what, 0,8) === 'https://'?'':self::CLOUD_API).$what;
		foreach($get_params+['depth'=>0] as $name => $value)
		{
			$url .= (strpos($url, '?') === false ? '?' : '&').$name.'='.urlencode($value);
		}
		if (in_array($method, ['POST', 'PUT', 'PATCH']))
		{
			if (!is_string($body))
			{
				$body = json_encode($body);
				$headers['Content-Type'] = 'application/json';
			}
		}
		else
		{
			$body = '';
		}
		if (!($f = self::httpOpen($url, $method, $body, $headers, $timeout)) ||
			!($response = stream_get_contents($f)) ||
			!($json = self::parseResponse($response)) ||
			!($data = json_decode($json, true)) ||
			empty($data['type']) && empty($data['token']) ||
			$data['type'] == 'collection' && (!isset($data['items']) || !is_array($data['items'])))
		{
			error_log("Request to '$url' failed: ".$response);
			if ($f) fclose($f);
            if (isset($data['messages']))
            {
                $messages = ': '.implode(', ', array_map(static function(array $message)
                {
                    return $message['message'].' ('.$message['errorCode'].')';
                }, $data['messages']));
            }
			throw new Api\Exception\NotFound("Request to '$url' failed".($messages??'!'), $data['httpStatus'] ?? 2);
		}
		if ($f) fclose($f);

		return $data['type'] == 'collection' ? $data['items'] : $data;
	}

	/**
	 * Open HTTP request
	 *
	 * @param string|array $url string with url or already passed like return from parse_url
	 * @param string $method ='GET'
	 * @param string $body =''
	 * @param array $header =array() additional header like array('Authentication' => 'basic xxxx')
	 * @param float $timeout =2 0 for async connection
	 * @return resource|boolean socket still in blocking mode
	 */
	protected static function httpOpen($url, $method='GET', $body='', array $header=array(), float $timeout=2)
	{
		if (empty($header['Authorization']) &&
			(empty(self::$config['username']) || empty(self::$config['password'])) &&
			empty(self::$config['token']))
		{
			Api\Egw::redirect_link('/index.php','menuaction=admin.admin_config.index&appname=profitbricks&ajax=true', 'admin');
		}
		// add default authentication header
		$header += ['Authorization' => !empty(self::$config['token']) ? 'Bearer '.self::$config['token'] :
			'Basic '.base64_encode(self::$config['username'].':'.self::$config['password'])];

		$parts = is_array($url) ? $url : parse_url($url);
		$addr = ($parts['scheme'] == 'https'?'ssl://':'tcp://').$parts['host'].':';
		$addr .= isset($parts['port']) ? (int)$parts['port'] : ($parts['scheme'] == 'https' ? 443 : 80);
		$errno = $errstr = null;
		if (!($sock = stream_socket_client($addr, $errno, $errstr, $timeout,
			$timeout ? STREAM_CLIENT_CONNECT : STREAM_CLIENT_ASYNC_CONNECTC)))
		{
			error_log(__METHOD__."('$url', ...) stream_socket_client('$addr', ...) $errstr ($errno)");
			return false;
		}
		$request = $method.' '.$parts['path'].(empty($parts['query'])?'':'?'.$parts['query'])." HTTP/1.1\r\n".
			"Host: ".$parts['host'].(empty($parts['port'])?'':':'.$parts['port'])."\r\n".
			"User-Agent: ".__CLASS__."\r\n".
			"Accept: application/json\r\n".
			"Cache-Control: no-cache\r\n".
			"Pragma:no-cache\r\n".
			"Connection: close\r\n";

		// Content-Length header is required for methods containing a body
		if (in_array($method, array('PUT','POST','PATCH')))
		{
			$header['Content-Length'] = strlen($body);
		}
		foreach($header as $name => $value)
		{
			$request .= $name.': '.$value."\r\n";
		}
		$request .= "\r\n";
		//if ($method != 'GET') error_log($request.$body);

		if (fwrite($sock, $request.$body) === false)
		{
			error_log(__METHOD__."('$url', ...) error sending request!");
			fclose($sock);
			return false;

		}
		return $sock;
	}

	/**
	 * Parse body from HTTP response and de-chunk it if necessary
	 *
	 * @param string $response
	 * @param ?array& $headers headers on return, lowercase name => value pairs
	 * @return string body of response
	 */
	protected static function parseResponse($response, array &$headers=null)
	{
		list($header, $body) = explode("\r\n\r\n", $response, 2);
		$headers = array();
		foreach(explode("\r\n", $header) as $line)
		{
			$parts = preg_split('/:\s*/', $line, 2);
			if (count($parts) == 2)
			{
				$headers[strtolower($parts[0])] = $parts[1];
			}
			else
			{
				$headers[] = $parts[0];
			}
		}
		// dechunk body if necessary
		if (isset($headers['transfer-encoding']) && $headers['transfer-encoding'] == 'chunked')
		{
			$chunked = $body;
			$body = '';
			while($chunked && (list($size, $chunked) = explode("\r\n", $chunked, 2)) && $size)
			{
				$body .= substr($chunked, 0, hexdec($size));
				if (true) $chunked = substr($chunked, hexdec($size)+2);	// +2 for "\r\n" behind chunk
			}
		}
		return $body;
	}

	protected static array $config;

	/**
	 * Initialize our static variables / config
	 *
	 * @throws Api\Exception\WrongParameter
	 */
	public static function initStatic()
	{
		self::$config = Api\Config::read(self::APP);

		// generate token to use instead of password
		if (!empty(self::$config['username']) && !empty(self::$config['password']) && empty(self::$config['token']))
		{
			if (self::$config['token'] = profitbricks_api::tokenGenerate())
			{
				Api\Config::save_value('token', self::$config['token'], self::APP);
				Api\Config::save_value('password', self::$config['password']=null, self::APP);
			}
		}
	}
}

Base::initStatic();