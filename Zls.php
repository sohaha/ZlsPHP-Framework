<?php
/**
 * Zls
 * @author        影浅
 * @email         seekwe@gmail.com
 * @copyright     Copyright (c) 2015 - 2018, 影浅, Inc.
 * @see           https://docs.73zls.com/zls-php/#/
 * @since         v2.4.5.1
 * @updatetime    2020-01-09 19:15:45
 */
define('IN_ZLS', '2.4.5.1');
define('ZLS_CORE_PATH', __FILE__);
define('SWOOLE_RESPONSE', 'SwooleResponse');
defined('ZLS_PREFIX') || define('ZLS_PREFIX', '__Z__');
defined('ZLS_PATH') || define('ZLS_PATH', getcwd() . '/');
defined('ZLS_RUN_MODE_PLUGIN') || define('ZLS_RUN_MODE_PLUGIN', true);
defined('ZLS_RUN_MODE_CLI') || define('ZLS_RUN_MODE_CLI', true);
defined('ZLS_APP_PATH') || define('ZLS_APP_PATH', Z::realPath(ZLS_PATH . 'app', true));
defined('ZLS_INDEX_NAME') || define('ZLS_INDEX_NAME', pathinfo(__FILE__, PATHINFO_BASENAME));
defined('ZLS_PACKAGES_PATH') || define('ZLS_PACKAGES_PATH', ZLS_APP_PATH . 'packages/');
interface Zls_Logger {
	public function write(\Zls_Exception $exception);
}
interface Zls_Request {
	public function getPathInfo();
	public function getQueryString();
}
interface Zls_Uri_Rewriter {
	public function rewrite($uri);
}
interface Zls_Exception_Handle {
	public function handle(\Zls_Exception $exception);
}
interface Zls_Maintain_Handle {
	public function handle();
}
interface Zls_Database_SlowQuery_Handle {
	public function handle($sql, $value, $explainString, $time, $trace);
}
interface Zls_Database_Index_Handle {
	public function handle($sql, $value, $explainString, $time, $trace);
}
interface Zls_Cache {
	public function set($key, $value, $cacheTime = 0);
	public function get($key);
	public function delete($key);
	public function clean();
	public function &instance($key = null, $isRead = true);
	public function reset();
}
/**
 * 内置方法.
 * @method \Zls_Router router()
 * @method static boolean isPost()
 * @method static boolean isPut()
 * @method static boolean isGet()
 * @method static boolean isDelete()
 * @method static boolean isOptions()
 * @method static array|string filesGet($key = null)
 * @method static \swoole_server swoole()
 */
class Z {
	private static $dbInstances = [];
	private static $globalData = [];
	private static $KeyPrefix = 'GlobalData_';
	/**
	 * 返回文件夹路径 / 不存在则创建
	 *
	 * @param string $path     文件夹路径
	 * @param bool   $addSlash 是否追加/
	 * @param bool   $isFile   是否是文件路径
	 * @param bool   $entr
	 * @param bool   $safeMode
	 *
	 * @return string
	 */
	public static function realPathMkdir($path, $addSlash = false, $isFile = false, $entr = true, $safeMode = true) {
		return self::tap(self::realPath($path, $addSlash, $entr), function ($path) use ($isFile, $safeMode) {
			if ($isFile) {
				$path = explode('/', $path);
				array_pop($path);
				$path = join('/', $path);
			}
			$mkdir = function () use ($path) {
				if (!is_dir($path)) {
					mkdir($path, 0777, true);
				}
			};
			$safeMode ? $mkdir() : self::forceUmask($mkdir);
		});
	}
	/**
	 * 延迟执行
	 */
	public static function defer(callable $fn) {
		self::eventBind(ZLS_PREFIX . 'DEFER', $fn);
	}
	/**
	 * 绑定事件
	 */
	public static function eventBind($name, callable $fn) {
		if ($events = self::di()->thing($name)) {
			$events[] = $fn;
			self::di()->bind($name, array_reverse($events));
		} else {
			self::di()->bind($name, [$fn]);
		}
	}
	/**
	 * 触发事件
	 */
	public static function eventEmit($name, $request = null) {
		if ($events = self::di()->thing($name)) {
			foreach ($events as $event) {
				$event($request);
			}
		}
	}
	/**
	 * 改变文件的文件权限（危险）
	 *
	 * @param      $callback
	 * @param null $mask
	 *
	 * @return array|object|string
	 */
	public static function forceUmask($callback, $mask = null) {
		$oldmask = umask(0);
		if (!is_null($mask)) {
			umask($mask);
		}
		return self::tap($callback(), function () use ($oldmask) {
			umask($oldmask);
		});
	}
	/**
	 * 简化临时变量
	 *
	 * @param string|array $value
	 * @param Callable     $callback
	 *
	 * @return string|object|array
	 */
	public static function tap($value, $callback) {
		$return = $callback($value);
		return is_null($return) ? $value : $return;
	}
	public static function realPath($path, $addSlash = false, $entr = true) {
		if (z::strBeginsWith(strtolower($path), 'phar://')) {
			return $path;
		}
		$unipath = PATH_SEPARATOR == ':';
		$separator = DIRECTORY_SEPARATOR;
		$prefix = realpath((false === $entr) ? (ZLS_PATH . '../') : (true !== $entr ? $entr : ZLS_PATH));
		if (false === strpos($path, ':') && strlen($path) && '/' != $path[0]) {
			$path = $prefix . $separator . $path;
		}
		$path = str_replace(['/', '\\'], $separator, $path);
		$parts = array_filter(explode($separator, $path), 'strlen');
		$absolutes = [];
		foreach ($parts as $part) {
			if ('.' == $part) {
				continue;
			}
			if ('..' == $part) {
				array_pop($absolutes);
			} else {
				$absolutes[] = $part;
			}
		}
		$path = join($separator, $absolutes);
		$path = $unipath ? (strlen($path) && '/' != $path[0] ? '/' . $path : $path) : $path;
		$path = str_replace(['/', '\\'], '/', $path);
		return $path . ($addSlash ? '/' : '');
	}
	/**
	 * 验证字符串开头
	 *
	 * @param string $str 源字符串
	 * @param string $sub 对比字符
	 *
	 * @return bool
	 */
	public static function strBeginsWith($str, $sub) {
		return substr($str, 0, strlen($sub)) == $sub;
	}
	public static function dump() {
		static $isXdebug;
		if (is_null($isXdebug) && $isXdebug = extension_loaded('xdebug')) {
			ini_set('xdebug.var_display_max_data', -1);
			ini_set('xdebug.var_display_max_depth', -1);
			ini_set('xdebug.var_display_max_children', -1);
			$isXdebug = (bool) ini_get('xdebug.overload_var_dump');
		}
		$beautify = (!Z::isCli() || Z::isSwoole(true)) && !$isXdebug && !self::isAjax();
		echo $beautify ? '<pre style="line-height:1.5em;font-size:14px;">' : "\n";
		@ob_start();
		$args = func_get_args();
		empty($args) ? null : call_user_func_array('var_dump', $args);
		$html = @ob_get_clean();
		echo $beautify ? htmlspecialchars($html) : $html;
		echo $beautify ? '</pre>' : "\n";
	}
	public static function isCli() {
		return PHP_SAPI == 'cli';
	}
	public static function isSwoole($isHttp = false) {
		static $swoole;
		if (is_null($swoole)) {
			$swoole = extension_loaded('swoole');
		}
		$isSwoole = ($swoole && array_key_exists('swoole', self::config()->getZMethods())) ? self::swoole()->worker_id >= 0 : false;
		return $isHttp ? $isSwoole && self::di()->has(SWOOLE_RESPONSE) : $isSwoole;
	}
	/**
	 * 获取配置信息
	 *
	 * @param null $configName
	 * @param bool $caching
	 * @param null $default
	 *
	 * @return mixed|null|Zls_Config|array
	 */
	public static function &config($configName = null, $caching = true, $default = null) {
		if (empty($configName)) {
			return Zls::getConfig();
		}
		$_info = explode('.', $configName);
		$configFileName = current($_info);
		static $loadedConfig = [];
		$cfg = null;
		if ($caching && self::arrayKeyExists($configFileName, $loadedConfig)) {
			$cfg = $loadedConfig[$configFileName];
		} elseif ($filePath = Zls::getConfig()->find($configFileName)) {
			$loadedConfig[$configFileName] = $cfg = include $filePath;
		} else {
			Z::throwIf(true, 500, 'config file [ ' . $configFileName . '.php ] not found', 'ERROR');
		}
		if ($cfg && count($_info) > 1) {
			$val = self::arrayGet($cfg, join('.', array_slice($_info, 1)), $default);
			return $val;
		} else {
			return $cfg;
		}
	}
	/**
	 * 数组是否包含key
	 *
	 * @param      $key
	 * @param      $arr
	 * @param bool $explode
	 *
	 * @return bool
	 */
	public static function arrayKeyExists($key, $arr, $explode = true) {
		if (empty($arr) || !is_array($arr)) {
			return false;
		}
		$keys = (true === $explode) ? explode('.', $key) : [$key];
		while (0 != count($keys)) {
			if (empty($arr) || !is_array($arr)) {
				return false;
			}
			$key = array_shift($keys);
			if (!array_key_exists($key, $arr)) {
				return false;
			}
			$arr = $arr[$key];
		}
		return true;
	}
	/**
	 * 简化抛出异常
	 *
	 * @param        $boolean
	 * @param        $exception
	 * @param string $message
	 * @param string $type
	 */
	public static function throwIf($boolean, $exception, $message = '', $type = 'NOTICE') {
		if ($boolean) {
			if (is_string($exception) || is_numeric($exception)) {
				$_exception = ucfirst($exception);
				$code = is_numeric($exception) ? $exception : 500;
				if (in_array($_exception, [500, 404, 'Database'])) {
					$exception = 'Zls_Exception_' . $_exception;
				}
				if (self::strBeginsWith($exception, 'Zls_Exception_')) {
					$trace = self::arrayGet(debug_backtrace(false), 0, ['file' => '', 'line' => 0]);
					throw new $exception($message, $code, $type, $trace['file'], $trace['line']);
				} else {
					throw new $exception($message, $code);
				}
			} else {
				throw $exception;
			}
		}
	}
	/**
	 * 获取数组的值
	 *
	 * @param      $arr
	 * @param      $keys
	 * @param null $default
	 * @param bool $explode
	 * @param bool $join
	 *
	 * @return mixed
	 */
	public static function arrayGet($arr, $keys, $default = null, $explode = true, $join = false) {
		if (!is_array($arr)) {
			$arr = [];
		}
		if (is_array($keys)) {
			$key = array_shift($keys);
		} else {
			$key = $keys;
			$keys = null;
		}
		$_keys = $explode ? explode('.', (string) $key) : [$key];
		$a = $arr;
		while (0 != count($_keys)) {
			$key = array_shift($_keys);
			if (!isset($a[$key])) {
				return $keys ? self::arrayGet($arr, $keys, $default, $explode, $join) : $default;
			}
			$a = $a[$key];
		}
		if ($join && is_array($a)) {
			$a = join(is_bool($join) ? ',' : $join, $a);
		}
		return $a;
	}
	/**
	 * 容器
	 *
	 * @param bool|null $remove
	 *
	 * @return Zls_Di|boolean|array
	 */
	public static function di($remove = false) {
		static $di;
		$cfg = Z::config();
		$uuid = $cfg->getSwooleUuid();
		$uuid = $cfg->getSwooleUuid();
		if ($di === null) {
			$di = new Zls_Di();
		}
		if ($uuid === '0' || !$cfg->getHasSwooleContext()) {
			return $di;
		}
		$key = '_SwooleDi_';
		if (!$newDi = \Zls\Swoole\Context::get($key, null)) {
			$newDi = new Zls_Di();
			$newDi->merge($di);
			\Zls\Swoole\Context::set($key, $newDi);
		}
		return $newDi;
	}
	public static function swooleUuid($prefix = '') {
		return self::config()->getSwooleUuid($prefix);
	}
	public static function wasteTime($time = null) {
		$wasteTime = 0;
		if ($time) {
			return Zls::$zlsTime = $time;
		} elseif (Zls::$zlsTime) {
			$wasteTime = z::microtime() - Zls::$zlsTime;
		}
		return $wasteTime;
	}
	/**
	 * 获取当前UNIX毫秒时间戳
	 * @return float
	 */
	public static function microtime() {
		list($s1, $s2) = explode(' ', microtime());
		$currentTime = (float) sprintf('%.0f', (floatval($s1) + floatval($s2)) * 1000);
		return $currentTime;
	}
	public static function memory($memory = null) {
		return $memory ? Zls::$zlsMemory = $memory : Zls::$zlsMemory ? number_format((memory_get_usage() - Zls::$zlsMemory) / 1024) : 0;
	}
	/**
	 * 获取系统临时目录路径
	 * @return string
	 */
	public static function tempPath() {
		$path = function () {
			if (!function_exists('sys_get_temp_dir')) {
				$_tmpKeys = ['TMPDIR', 'TEMP', 'TMP', 'upload_tmp_dir'];
				foreach ($_tmpKeys as $v) {
					if (!empty($_ENV[$v])) {
						return realpath($_ENV[$v]);
					}
				}
				$tempfile = tempnam(uniqid(rand(), true), '');
				if (file_exists($tempfile)) {
					unlink($tempfile);
					return realpath(dirname($tempfile));
				}
				return '/tmp';
			}
			return sys_get_temp_dir();
		};
		return self::realPath($path(), true);
	}
	/**
	 * 追踪打印日志
	 *
	 * @param bool $instance
	 *
	 * @return bool|Zls_Trace
	 */
	public static function trace($instance = false) {
		if (self::config()->getTraceStatus()) {
			$_trace = self::log(null, false);
			if (true === $instance) {
				return $_trace;
			}
			$trace = debug_backtrace();
			$traceData = [];
			$len = count($trace) - 1;
			for ($i = 1; $i <= $len; $i++) {
				$t = $trace[$i];
				if (!$t['function']) {
					break;
				}
				$traceData[] = ['function' => Z::arrayGet($trace, ($i + 1) . '.function', ''), 'file' => $t['file'], 'line' => $t['line']];
			}
			$trace[0]['trace'] = &$traceData;
			$_trace->log($trace[0], 'trace');
			return true;
		}
		return false;
	}
	/**
	 * 保存日志
	 *
	 * @param string $log
	 * @param string $type
	 * @param bool   $debug
	 *
	 * @return bool|Zls_Trace
	 */
	public static function log($log = '', $type = 'log', $debug = false) {
		if (!self::config()->getTraceStatus($type)) {
			return false;
		}
		$trace = new Zls_Trace();
		if ((bool) $type) {
			if ($debug) {
				$debug = self::debug(null, false, true, false);
				$current = self::arrayGet(debug_backtrace(), 0, ['file' => '', 'line' => '']);
				$debug['file'] = $current['file'] ? self::safePath($current['file']) : null;
				$debug['line'] = $current['line'];
			}
			$trace->output($log, $type, $debug);
		}
		return $trace;
	}
	/**
	 * 获取执行时间与内存
	 *
	 * @param string $name
	 * @param bool   $output
	 * @param bool   $suffix
	 * @param bool   $resString
	 * @param bool   $unset
	 *
	 * @return array|string
	 * @internal param bool $end
	 */
	public static function debug($name = '', $output = false, $suffix = true, $resString = true, $unset = true) {
		list($_run, $_mem) = self::_debug();
		$toStr = function ($res) use ($name, $resString) {
			return $res ? ($resString ? vsprintf($name . '[runtime:%s,memory:%s', [$res['runtime'], $res['memory'] . ']']) : $res) : true;
		};
		if ($name) {
			if ($output) {
				$runTime = self::microtime() - $_run[$name];
				$res = ['runtime' => $runTime / 1000 . ($suffix ? 's' : ''), 'memory' => self::convertRam(memory_get_usage() - $_mem[$name], $suffix)];
				if ($unset) {
					unset($_run[$name], $_mem[$name]);
				}
			} else {
				$_run[$name] = self::microtime();
				$_mem[$name] = memory_get_usage();
				$res = null;
			}
			self::_debug([$_run, $_mem]);
		} else {
			$runTime = ceil(Zls::$zlsTime) !== Zls::$zlsTime ? Zls::$zlsTime * 1000 : Zls::$zlsTime;
			$runTime = self::microtime() - $runTime;
			if (substr_count($runTime, 'E')) {
				$runTime = floatval(substr($runTime, 5));
			}
			$res = ['runtime' => ($runTime / 1000) . ($suffix ? 's' : ''), 'memory' => (Zls::$zlsMemory ? self::convertRam(memory_get_usage() - Zls::$zlsMemory, $suffix) : 'null')];
		}
		return $toStr($res);
	}
	private static function _debug($data = null) {
		if (!self::di()->has('debug')) {
			$data = [[], []];
		} elseif (is_null($data)) {
			return self::di()->make('debug');
		}
		self::di()->bind('debug', function () use ($data) {
			return $data;
		});
		return true;
	}
	/**
	 * 计算内存消耗
	 *
	 * @param      $size
	 * @param bool $suffix
	 *
	 * @return string
	 */
	public static function convertRam($size, $suffix = true) {
		if (!$suffix) {
			return $size;
		}
		if ($size <= 0) {
			return 0;
		}
		$unit = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];
		$i = floor(log($size, 1024));
		return @round($size / pow(1024, $i), 2) . $unit[$i];
	}
	/**
	 * 屏蔽路径中系统的绝对路径部分，转换为安全的用于显示
	 *
	 * @param string $path
	 * @param string $prefix
	 * @param bool   $entr 是否基于入口路径
	 *
	 * @return string
	 */
	public static function safePath($path, $prefix = '~APP~', $entr = false) {
		if (!$path) {
			return '';
		}
		$path = self::realPath($path);
		$siteRoot = is_bool($entr) ? self::realPath('.', false, $entr) : $entr;
		$_path = str_replace($siteRoot, '', $path);
		return $prefix . str_replace($siteRoot, '', $_path);
	}
	/**
	 * 路径是否在指定目录范围内
	 * @return bool
	 */
	public function inPath($path, $target, $children = true) {
		$path = self::realPath($path);
		$target = self::realPath($target);
		if ($children && self::strBeginsWith($path, $target)) {
			return true;
		}
		return $path === $target;
	}
	/**
	 * $_SERVER参数值
	 * @param null $key
	 * @param null $default
	 *
	 * @return mixed
	 */
	public static function server($key = null, $default = null) {
		$server = self::getGlobalData(ZLS_PREFIX . 'server', []);
		return is_null($key) ? $server : self::arrayGet($server, strtoupper($key), $default);
	}
	/**
	 * 数组指定key过滤
	 *
	 * @param      $keys
	 * @param      $arr
	 * @param bool $in
	 *
	 * @return array
	 */
	public static function arrayKeyFilter($keys, $arr, $in = false) {
		$keys = !is_array($keys) ? explode(',', $keys) : $keys;
		$arr = self::arrayFilter($arr, function ($v, $k) use ($keys, $in) {
			return (!$in && !in_array($k, $keys, true)) ? true : ($in && in_array($k, $keys, true));
		});
		return $arr;
	}
	/**
	 * 数组过滤
	 *
	 * @param array    $arr
	 * @param callable $callback
	 *
	 * @return array
	 */
	public static function arrayFilter(array $arr, callable $callback) {
		if (self::phpCanV('5.6.0')) {
			return array_filter($arr, $callback, ARRAY_FILTER_USE_BOTH);
		} else {
			$newArr = [];
			foreach ($arr as $k => $v) {
				if ($_value = $callback($v, $k)) {
					$newArr[$k] = $v;
				}
			}
			return $newArr;
		}
	}
	public static function phpCanV($version = '5.4.0') {
		return version_compare(PHP_VERSION, $version, '>=');
	}
	/**
	 * 数组去重并重排
	 *
	 * @param $arr
	 *
	 * @return array
	 */
	public static function arrayUnique($arr) {
		return array_values(array_flip(array_flip($arr)));
	}
	public static function resetZls() {
		$config = self::config();
		Zls::$loadedModules = [];
		if ($config->getCacheConfig()) {
			self::cache()->reset();
		}
		self::clearDb();
		Zls_Logger_Dispatcher::setMemReverse();
	}
	/**
	 * 获取缓存操作对象
	 *
	 * @param string|array $cacheType
	 *
	 * @return \Zls_Cache
	 */
	public static function cache($cacheType = null) {
		return self::config()->getCacheHandle($cacheType);
	}
	private static function clearDb($key = null) {
		if (!is_null($key)) {
			self::$dbInstances[$key]->close();
			unset(self::$dbInstances[$key]);
		} else {
			self::$dbInstances = [];
		}
	}
	/**
	 * 执行任务
	 *
	 * @param              $taksName
	 * @param array|string $args
	 * @param string|null  $user
	 * @param string|null  $phpPath
	 * @param bool         $logFile
	 *
	 * @return string
	 */
	public static function task($taksName, $args = null, $user = '', $phpPath = null, $logFile = false) {
		$phpPath = $phpPath ?: self::phpPath();
		$argc = '';
		if (is_array($args)) {
			foreach ($args as $key => $value) {
				$argc .= " -{$key} $value";
			}
		} else {
			$argc = ' ' . $args;
		}
		$index = ZLS_PATH . ZLS_INDEX_NAME;
		if (!self::isWin() && (is_null($user) || $user === '')) {
			$user = self::whoami();
		}
		$cmd = "{$phpPath} {$index}  -task {$taksName}{$argc}";
		self::command($cmd, $user, $logFile);
		return $cmd;
	}
	public static function whoami() {
		return trim(self::command('whoami', '', true));
	}
	/**
	 * 获取php执行路径
	 * @return mixed|string
	 */
	public static function phpPath() {
		static $phpPath;
		if (!$phpPath) {
			if (strpos(strtolower(PHP_OS), 'win') !== false) {
				if ($path = z::arrayGet(ini_get_all(), 'extension_dir.local_value', '')) {
					$phpPath = str_replace('\\', '/', $path);
					$phpPath = str_replace('/ext', '/', $phpPath);
					$realPath = $phpPath . 'php.exe';
				} else {
					$realPath = 'php.exe';
				}
			} else {
				$realPath = PHP_BINDIR . '/php';
			}
			if (false !== strpos($realPath, 'ephp.exe')) {
				$realPath = str_replace('ephp.exe', 'php.exe', $realPath);
			}
			if (!is_file($realPath)) {
				$realPath = 'php';
			}
			$phpPath = $realPath;
		}
		return $phpPath;
	}
	/**
	 * Windows环境
	 * @return bool
	 */
	public static function isWin() {
		return DIRECTORY_SEPARATOR === '\\';
	}
	/**
	 * 执行外部命令
	 *
	 * @param             $cmd
	 * @param string      $user
	 * @param bool|string $logfile true直接输出,false不输出,或指定输入日志路径
	 * @param bool        $escape
	 *
	 * @return string
	 */
	public static function command($cmd, $user = '', $logfile = true, $escape = true) {
		$disabled = explode(',', ini_get('disable_functions'));
		if ($escape && !in_array('escapeshellcmd', $disabled)) {
			$cmd = escapeshellcmd($cmd);
		}
		$exportLogfile = is_string($logfile) ? $logfile : false;
		if (!$logfile || $exportLogfile) {
			if (self::isWin()) {
				$cmd = "start /b {$cmd} " . ($exportLogfile ? '>> ' . $exportLogfile : '> NUL');
			} else {
				if ($user && $user !== self::whoami()) {
					$cmd = 'sudo -u ' . $user . ' ' . $cmd;
				}
				$cmd = 'nohup ' . $cmd . ' ' . ($exportLogfile ? '>> ' . $exportLogfile : '> /dev/null') . ' 2>&1 &';
			}
		}
		@ob_start();
		switch (true) {
		case !in_array('popen', $disabled):
			$fp = popen($cmd, 'r');
			if ($logfile) {
				while (!feof($fp)) {
					echo fread($fp, 1024);
				}
			}
			pclose($fp);
			break;
		case !in_array('shell_exec', $disabled):
			echo shell_exec($cmd);
			break;
		case !in_array('passthru', $disabled):
			passthru($cmd);
			break;
		case !in_array('exec', $disabled):
			exec($cmd, $res);
			echo join("\n", $res);
			break;
		case !in_array('system', $disabled):
			system($cmd);
			break;
		default:
			@ob_end_clean();
			self::throwIf(true, 500, 'Your environment doesn\'t support task execution, Please check the PHP ini disable_functions, [ shell_exec,popen,exec,system ] must open one of them.');
		}
		$result = @ob_get_clean();
		return $result ? self::toUtf8($result) : $result;
	}
	/**
	 * 非utf8字符串转换成utf8
	 *
	 * @param $str
	 *
	 * @return null|string
	 */
	public static function toUtf8($str) {
		$encode = mb_detect_encoding($str, ['UTF-8', 'GB2312', 'GBK']);
		return 'UTF-8' === $encode ? $str : mb_convert_encoding($str, 'UTF-8', $encode);
	}
	/**
	 * 扫描目录文件
	 *
	 * @param               $dir
	 * @param int           $depth
	 * @param null|Callable $fn
	 *
	 * @return array
	 */
	public static function scanFile($dir, $depth = 0, $fn = null) {
		$dirs = ['folder' => [], 'file' => []];
		if (is_dir($dir)) {
			if ($dh = opendir($dir)) {
				while (false !== ($file = readdir($dh))) {
					if ($depth >= 0 && '.' != $file && '..' != $file && !(is_callable($fn) && (false === $fn($dir, $file)))) {
						if ((is_dir($dir . '/' . $file))) {
							$dirs['folder'][$file] = self::scanFile($dir . '/' . $file . '/', $depth - 1);
						} else {
							$dirs['file'][] = $file;
						}
					}
				}
				closedir($dh);
			}
		}
		return $dirs;
	}
	public static function stripSlashes($var) {
		if (!get_magic_quotes_gpc()) {
			return $var;
		}
		if (is_array($var)) {
			foreach ($var as $key => $val) {
				if (is_array($val)) {
					$var[$key] = self::stripSlashes($val);
				} else {
					$var[$key] = stripslashes($val);
				}
			}
		} elseif (is_string($var)) {
			$var = stripslashes($var);
		}
		return $var;
	}
	/**
	 * 实例业务层
	 *
	 * @param       $businessName
	 * @param bool  $shared
	 * @param array $args
	 *
	 * @return object
	 */
	public static function business($businessName, $shared = true, $args = []) {
		$name = Zls::getConfig()->getBusinessDirName() . '/' . $businessName;
		$object = self::factory($name, $shared, null, $args);
		Z::throwIf(!($object instanceof Zls_Business), 500, '[ ' . $name . ' ] not a valid Zls_Business', 'ERROR');
		return $object;
	}
	/**
	 * 超级方法
	 *
	 * @param string $className      可以是完整的控制器类名，模型类名，类库类名
	 * @param string $hmvcModuleName hmvc模块名称，是配置里面的数组的键名，插件模式下才会用到这个参数
	 * @param bool   $shared
	 * @param array  $args
	 *
	 * @return object
	 */
	public static function factory($className, $shared = false, $hmvcModuleName = null, $args = []) {
		if (self::config()->getRoute()->getHmvcModuleName() && !self::strBeginsWith($className, 'Hmvc_')) {
			$className = 'Hmvc_' . $className;
		}
		if (self::strEndsWith(strtolower($className), '.php')) {
			$className = substr($className, 0, strlen($className) - 4);
		}
		$className = str_replace(['/', '_'], '\\', $className);
		if ($hmvcModuleName) {
			$hmvcFlip = self::config()->getHmvcModules();
			$hmvcModuleName = self::arrayGet($hmvcFlip, $hmvcModuleName, $hmvcModuleName);
			$className = $hmvcModuleName . '\\' . $className;
		}
		if (!self::di()->has($className)) {
			self::di()->bind($className, ['class' => $className, 'hmvc' => $hmvcModuleName]);
		}
		return !$shared ? self::di()->make($className, $args) : self::di()->makeShared($className, $args);
	}
	/**
	 * 验证字符串结尾
	 *
	 * @param $str
	 * @param $sub
	 *
	 * @return bool
	 */
	public static function strEndsWith($str, $sub) {
		return substr($str, strlen($str) - strlen($sub)) == $sub;
	}
	/**
	 * 逗号字符串
	 *
	 * @param        $str
	 * @param array  $intersect
	 * @param string $delimiter
	 *
	 * @return array
	 */
	public static function strComma($str, $intersect = [], $delimiter = ',') {
		if (!is_array($str)) {
			$str = explode($delimiter, $str);
		}
		if ($intersect) {
			$str = array_intersect($intersect, $str);
		}
		return $str;
	}
	/**
	 * 将驼峰式字符串转化为特定字符串
	 *
	 * @param        $str
	 * @param string $delimiter 分隔符
	 *
	 * @return string
	 */
	public static function strCamel2Snake($str, $delimiter = '_') {
		$str = str_split($str);
		foreach ($str as $k => &$v) {
			if (preg_match('/^[A-Z]+$/', $v)) {
				$last = self::arrayGet($str, ($k - 1));
				if ($last && ('/' != $last)) {
					$v = $delimiter . $v;
				}
			}
		}
		return strtolower(join('', $str));
	}
	/**
	 * 判断是否是插件模式运行
	 * @return bool
	 */
	public static function isPluginMode() {
		return defined('ZLS_RUN_MODE_PLUGIN') && ZLS_RUN_MODE_PLUGIN;
	}
	/**
	 * 实例控制器
	 *
	 * @param string|object $controller
	 * @param null          $method
	 * @param array         $args
	 * @param null          $hmvcModuleName
	 * @param bool          $middleware
	 * @param bool          $requestMethod
	 *
	 * @return object|string
	 */
	public static function controller($controller, $method = null, $args = [], $hmvcModuleName = null, $middleware = false, $requestMethod = false) {
		if ((bool) $hmvcModuleName) {
			Zls::checkHmvc($hmvcModuleName);
		}
		if (is_string($controller)) {
			$class = z::strBeginsWith($controller, 'Controller') || z::strBeginsWith($controller, '\\') ? $controller : Zls::getConfig()->getControllerDirName() . '_' . $controller;
			Z::throwIf(!Z::classIsExists($class), 404, 'Controller [ ' . $class . ' ] not found');
			$controllerObject = self::factory($class, true);
			$originalClass = str_replace('_', '\\', $class);
		} else {
			$controllerObject = $controller;
			$originalClass = $class = get_class($controllerObject);
		}
		Z::throwIf(!($controllerObject instanceof Zls_Controller), 500, '[ ' . $class . ' ] not a valid Zls_Controller', 'ERROR');
		$config = self::config();
		$controllerShort = str_replace([$config->getControllerDirName() . '\\', $config->getControllerDirName() . '_'], '', $class);
		if (!$method && !is_null($method)) {
			$method = Z::config()->getDefaultMethod();
		}
		if ($method) {
			$middlewares = [];
			$methodFull = $config->getMethodPrefix() . $method;
			if ($requestMethod && $requestMethod = Z::server('request_method')) {
				$requestMethodFull = $requestMethod . $methodFull;
				if (method_exists($controllerObject, $requestMethodFull)) {
					$methodFull = $requestMethodFull;
				}
			}
			$after = $middleware && method_exists($controllerObject, 'after') ? function ($contents, $method, $controllerShort, $args, $methodFull, $class) use ($controllerObject) {
				$contents = $controllerObject->after($contents, $method, $controllerShort, $args, $methodFull, $class);
				if (is_array($contents)) {
					$contents = Z::view()->set($contents)->load("$controllerShort/$method", [], true);
				}
				return $contents;
			} : function ($contents) {
				return $contents;
			};
			$transform = function ($str) {
				return '\\' . str_replace('_', '\\', $str);
			};
			if ($middleware && method_exists($controllerObject, 'before')) {
				$middlewares[] = function ($request, callable $next) use ($controllerObject, $after, $transform) {
					$contents = $controllerObject->before($request['method'], $request['controllerShort'], $request['args'], $request['methodFull'], $transform($request['class']));
					return is_null($contents) ? $next($request) : $after($contents, $request['method'], $request['controllerShort'], $request['args'], $request['methodFull'], $transform($request['class']));
				};
			}
			if (!method_exists($controllerObject, $methodFull)) {
				$containCall = method_exists($controllerObject, 'call');
				Z::throwIf(!$containCall, 404, 'Method' . ($requestMethod ? "({$requestMethod})" : '') . ' [ ' . $class . '->' . $methodFull . '() ] not found');
				$middlewares[] = function ($request, callable $next) use ($after, $controllerObject, $transform) {
					$contents = $controllerObject->call($request['method'], $request['controllerShort'], $request['args'], $request['methodFull'], $transform($request['class']));
					return $after($contents, $request['method'], $request['controllerShort'], $request['args'], $request['methodFull'], $transform($request['class']));
				};
			} elseif ($after) {
				$middlewares[] = function ($request, callable $next) use ($after, $transform) {
					$contents = $next($request);
					return $after($contents, $request['method'], $request['controllerShort'], $request['args'], $request['methodFull'], $transform($request['class']));
				};
			}
			$sendData = ['originalClass' => $originalClass, 'method' => $method, 'controllerShort' => $controllerShort, 'args' => $args, 'methodFull' => $methodFull, 'class' => $class];
			return (new Zls_Pipeline)->send($sendData)->then($config->getHttpMiddleware(), function () use ($middlewares, $sendData, $controllerObject, $methodFull, $args) {
				return (new Zls_Pipeline)->send($sendData)->then($middlewares, function () use ($controllerObject, $methodFull, $args) {
					return call_user_func_array([$controllerObject, $methodFull], $args);
				});
			});
		} else {
			return $controllerObject;
		}
	}
	public static function classIsExists($class) {
		if (class_exists($class, false)) {
			return true;
		}
		$classNamePath = str_replace('_', '/', $class);
		foreach (self::config()->getPackages() as $path) {
			if (file_exists($filePath = $path . self::config()->getClassesDirName() . '/' . $classNamePath . '.php')) {
				return true;
			}
		}
		return false;
	}
	/**
	 * exit/die代替
	 *
	 * @param string $msg
	 */
	public static function end($msg = '') {
		if (self::config()->runState && !ZLS_RUN_MODE_PLUGIN) {
			throw new Zls_Exception_Exit($msg);
		} else {
			echo $msg;
			exit;
		}
	}
	/**
	 * @return Zls_View
	 */
	public static function view() {
		return self::factory('Zls_View');
	}
	/**
	 * @param       $daoName
	 * @param bool  $shared
	 * @param array $args
	 *
	 * @return Zls_Dao
	 */
	public static function dao($daoName, $shared = false, $args = []) {
		$name = Zls::getConfig()->getDaoDirName() . '/' . $daoName;
		$object = self::factory($name, $shared, null, $args);
		Z::throwIf(!($object instanceof Zls_Dao), 500, '[ ' . $name . ' ] not a valid Zls_Dao', 'ERROR');
		return $object;
	}
	/**
	 * @param       $beanName
	 * @param       $row
	 * @param bool  $shared
	 * @param array $args
	 *
	 * @return Zls_Bean
	 */
	public static function bean($beanName, $row = [], $shared = true) {
		if ('object' === gettype($beanName)) {
			$object = $beanName;
			$name = get_class($beanName);
		} else {
			$name = Zls::getConfig()->getBeanDirName() . '/' . $beanName;
			$object = self::factory($name, $shared, null, []);
			$object->init($row);
		}
		self::throwIf(!($object instanceof Zls_Bean), 500, '[ ' . $name . ' ] not a valid Zls_Bean', 'ERROR');
		return $object;
	}
	/**
	 * 将特定字符串转化为按驼峰式
	 *
	 * @param        $str
	 * @param string $Delimiter 分隔符
	 * @param bool   $ucfirst
	 *
	 * @return mixed|string
	 */
	public static function strSnake2Camel($str, $ucfirst = true, $Delimiter = '_') {
		$str = ucwords(str_replace($Delimiter, ' ', $str));
		$str = str_replace(' ', '', lcfirst($str));
		return $ucfirst ? ucfirst($str) : $str;
	}
	/**
	 * 模型
	 *
	 * @param       $modelName
	 * @param bool  $shared
	 * @param array $args
	 *
	 * @return object
	 */
	public static function model($modelName, $shared = true, $args = []) {
		$name = Zls::getConfig()->getModelDirName() . '/' . $modelName;
		$object = self::factory($name, $shared, null, $args);
		Z::throwIf(!($object instanceof Zls_Model), 500, '[ ' . $name . ' ] not a valid Zls_Model', 'ERROR');
		return $object;
	}
	/**
	 * @param       $lName
	 * @param bool  $shared
	 * @param array $args
	 *
	 * @return object
	 */
	public static function library($lName, $shared = false, $args = []) {
		return self::factory($lName, $shared, null, $args);
	}
	/**
	 * @param $functionFilename
	 */
	public static function functions($functionFilename) {
		static $loadedFunctionsFile = [];
		if (self::arrayKeyExists($functionFilename, $loadedFunctionsFile)) {
			return;
		} else {
			$loadedFunctionsFile[$functionFilename] = 1;
		}
		$config = Zls::getConfig();
		$found = false;
		foreach ($config->getPackages() as $packagePath) {
			$filePath = $packagePath . $config->getFunctionsDirName() . '/' . $functionFilename . '.php';
			if (file_exists($filePath)) {
				self::includeOnce($filePath);
				$found = true;
				break;
			}
		}
		Z::throwIf(!$found, 500, 'functions file [ ' . $functionFilename . '.php ] not found', 'ERROR');
	}
	/**
	 * 引入文件 优化版
	 *
	 * @param string $filePath 文件路径
	 */
	public static function includeOnce($filePath) {
		static $includeFiles = [];
		$key = md5(self::realPath($filePath));
		if (!self::arrayKeyExists($key, $includeFiles, false)) {
			if (self::isSwoole()) {
				include_once $filePath;
			} else {
				include $filePath;
			}
			$includeFiles[$key] = 1;
		}
	}
	/**
	 * 解析命令行参数 $GLOBALS['argv'] 到一个数组
	 */
	public static function getOpt($key = null, $default = null) {
		if (!self::isCli()) {
			return null;
		}
		static $result = [];
		static $parsed = false;
		if (!$parsed) {
			$parsed = true;
			$params = self::arrayGet($GLOBALS, 'argv', []);
			$jumpKey = [];
			foreach ($params as $k => $p) {
				if (!in_array($k, $jumpKey, true)) {
					if ((bool) $p) {
						$pname = null;
						$value = true;
						if (strpos($p, '=') !== false) {
							$_tmp = explode('=', $p, 2);
							$pname = $_tmp[0];
							$value = $_tmp[1];
						} elseif ('-' == $p[0]) {
							$pname = substr($p, 1);
						}
						if ($pname) {
							$nextparm = z::arrayGet($params, $k + 1);
							if (true === $value && null !== $nextparm && !((bool) $nextparm && is_string($nextparm) && '-' == $nextparm[0])) {
								$value = $nextparm;
								$jumpKey[] = $k + 1;
							}
							if (isset($result[$pname])) {
								if (is_array($result[$pname])) {
									$result[$pname][] = $value;
								} else {
									$result[$pname] = [$result[$pname], $value];
								}
							} else {
								$result[$pname] = $value;
							}
						} else {
							$result[] = $p;
						}
					} else {
						$result[] = $p;
					}
				}
			}
		}
		return empty($key) ? $result : (self::arrayKeyExists($key, $result) ? $result[$key] : $default);
	}
	public static function postGet($key = null, $default = null, $xssClean = true) {
		if (is_null($key)) {
			$value = self::post() ?: self::get();
		} else {
			$postValue = self::post($key, null, $xssClean);
			$value = is_null($postValue) ? self::get($key, $default, $xssClean) : $postValue;
		}
		return $value;
	}
	public static function post($key = null, $default = null, $xssClean = true) {
		$post = self::getGlobalData(ZLS_PREFIX . 'post', []);
		$value = is_null($key) ? $post : self::arrayGet($post, $key, $default);
		return $xssClean ? self::xssClean($value) : $value;
	}
	/**
	 * xss过滤
	 */
	public static function xssClean($var) {
		if (is_array($var)) {
			foreach ($var as $key => $val) {
				if (is_array($val)) {
					$var[$key] = self::xssClean($val);
				} else {
					$var[$key] = self::xssClean0($val);
				}
			}
		} elseif (is_string($var)) {
			$var = self::xssClean0($var);
		}
		return $var;
	}
	private static function xssClean0($data) {
		$data = str_replace(['&amp;', '&lt;', '&gt;'], ['&amp;amp;', '&amp;lt;', '&amp;gt;'], $data);
		$data = preg_replace('/(&#*\w+)[\x00-\x20]+;/u', '$1;', $data);
		$data = preg_replace('/(&#x*[0-9A-F]+);*/iu', '$1;', $data);
		$data = html_entity_decode($data, ENT_COMPAT, 'UTF-8');
		$data = preg_replace('#(<[^>]+?[\x00-\x20"\'])(?:on|xmlns)[^>]*+>#iu', '$1>', $data);
		$data = preg_replace(
			'#([a-z]*)[\x00-\x20]*=[\x00-\x20]*([`\'"]*)[\x00-\x20]*j[\x00-\x20]*a[\x00-\x20]*v[\x00-\x20]*a[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu',
			'$1=$2nojavascript...',
			$data
		);
		$data = preg_replace(
			'#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*v[\x00-\x20]*b[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu',
			'$1=$2novbscript...',
			$data
		);
		$data = preg_replace(
			'#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*-moz-binding[\x00-\x20]*:#u',
			'$1=$2nomozbinding...',
			$data
		);
		$data = preg_replace(
			'#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?expression[\x00-\x20]*\([^>]*+>#i',
			'$1>',
			$data
		);
		$data = preg_replace(
			'#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?behaviour[\x00-\x20]*\([^>]*+>#i',
			'$1>',
			$data
		);
		$data = preg_replace(
			'#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:*[^>]*+>#iu',
			'$1>',
			$data
		);
		$data = preg_replace('#</*\w+:\w[^>]*+>#i', '', $data);
		do {
			$old_data = $data;
			$data = preg_replace(
				'#</*(?:applet|b(?:ase|gsound|link)|embed|iframe|frame(?:set)?|i(?:frame|layer)|l(?:ayer|ink)|meta|object|s(?:cript|tyle)|title|xml)[^>]*+>#i',
				'',
				$data
			);
		} while ($old_data !== $data);
		return $data;
	}
	public static function get($key = null, $default = null, $xssClean = true) {
		$get = self::getGlobalData(ZLS_PREFIX . 'get', []);
		$value = is_null($key) ? $get : self::arrayGet($get, $key, $default);
		return $xssClean ? self::xssClean($value) : $value;
	}
	/**
	 * 获取session值
	 */
	public static function session($key = null, $default = null, $xssClean = false) {
		$id = self::sessionStart();
		$session = (self::isSwoole(true) && ($sessionHandle = self::config()->getSessionHandle())) ? $sessionHandle->swooleRead($id) : self::getGlobalData(ZLS_PREFIX . 'session');
		//$session = (self::isSwoole(true) && ($sessionHandle = self::config()->getSessionHandle())) ? $sessionHandle->swooleGet(null) : $_SESSION;
		$value = is_null($key) ? (empty($session) ? [] : $session) : self::arrayGet($session, $key, $default);
		return $xssClean ? self::xssClean($value) : $value;
	}
	/**
	 * 开启session
	 *
	 * @param string $id 自定义session_id
	 */
	public static function sessionStart($id = null) {
		if (!!$id || !self::di()->has('ZlsSessionID')) {
			$sessionId = '';
			if (!self::isCli()) {
				if (self::phpCanV()) {
					$started = PHP_SESSION_ACTIVE === session_status() ? true : false;
				} else {
					$started = '' === session_id() ? false : true;
				}
				if (!$started && !headers_sent()) {
					if (!is_null($id)) {
						session_id($id);
					}
					session_start();
				}
				$sessionId = session_id();
			} elseif (self::isSwoole(true)) {
				$sessionConfig = self::config()->getSessionConfig();
				$sessionName = $sessionConfig['session_name'];
				$sessionId = $id ?: z::cookieRaw($sessionName);
				if (!$sessionId) {
					$sessionId = md5(uniqid(z::clientIp(), true)) . mt_rand(1000, 9999);
					z::setCookieRaw($sessionName, $sessionId, time() + $sessionConfig['lifetime'], '/');
				}
				$sessionHandle = self::config()->getSessionHandle();
				self::throwIf(!$sessionHandle, 500, 'swoole mode must set the SessionHandle');
				$sessionHandle->swooleInit($sessionId);
			}
			if ($sessionId) {
				self::di()->bind('ZlsSessionID', function () use ($sessionId) {
					return $sessionId;
				});
			}
			return $sessionId;
		} else {
			return self::di()->makeShared('ZlsSessionID');
		}
	}
	public static function cookieRaw($key = null, $default = null, $xssClean = false) {
		$cookie = self::getGlobalData(ZLS_PREFIX . 'cookie');
		$value = is_null($key) ? $cookie : self::arrayGet($cookie, $key, $default);
		return $xssClean ? self::xssClean($value) : $value;
	}
	/**
	 * 获取客户端IP
	 *
	 * @param array $source
	 * @param array $check
	 *
	 * @return bool|mixed|string
	 */
	public static function clientIp($check = null, $source = null) {
		$clientIpConditions = self::config()->getClientIpConditions();
		if (is_null($check)) {
			$check = $clientIpConditions['check'];
		}
		if (is_null($source)) {
			$source = $clientIpConditions['source'];
		}
		$source && array_walk($source, function (&$v) {
			$v = strtoupper($v);
		});
		$check && array_walk($check, function (&$v) {
			$v = strtoupper($v);
		});
		$checkClientIp = function () {
			$ip = self::server('REMOTE_ADDR');
			if (empty($ip)) {
				return false;
			}
			return self::isWhiteIp($ip, self::config()->getBackendServerIpWhitelist());
		};
		$defaultIp = '0.0.0.0';
		$filterIp = function ($ip) use ($defaultIp) {
			return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : $defaultIp;
		};
		foreach ($source as $v) {
			if ($ip = self::server($v)) {
				if (!in_array($v, $check)) {
					return $filterIp($ip);
				}
				if ($checkClientIp()) {
					return $filterIp($ip);
				} else {
					continue;
				}
			}
		}
		return $defaultIp;
	}
	public static function setCookieRaw($key, $value, $life = null, $path = '/', $domian = null, $httpOnly = false) {
		if (!self::isCli()) {
			self::header('P3P: CP="CURa ADMa DEVa PSAo PSDo OUR BUS UNI PUR INT DEM STA PRE COM NAV OTC NOI DSP COR"');
		}
		if (!is_null($domian)) {
			$autoDomain = $domian;
		} else {
			$host = explode(':', self::server('HTTP_HOST'));
			$domian = $host[0];
			$is_ip = preg_match('/^((25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(25[0-5]|2[0-4]\d|[01]?\d\d?)$/', $domian);
			$notRegularDomain = preg_match('/^[^\\.]+$/', $domian);
			if ($is_ip) {
				$autoDomain = $domian;
			} elseif ($notRegularDomain) {
				$autoDomain = null;
			} else {
				$autoDomain = '.' . $domian;
			}
		}
		self::setGlobalData(ZLS_PREFIX . 'setCookie', array_merge(self::getGlobalData(ZLS_PREFIX . 'setCookie', []), [[(string) $key, (string) $value, (int) ($life ? $life + time() : null), $path, $autoDomain, 443 == self::server('SERVER_PORT'), $httpOnly]]));
		$cookie = Z::cookieRaw();
		$cookie[$key] = $value;
		Z::setGlobalData(ZLS_PREFIX . 'cookie', $cookie);
	}
	public static function getGlobalData($id, $def = null) {
		return Z::config()->getHasSwooleContext() && Z::config()->getSwooleUuid() ? \Zls\Swoole\Context::get($id, $def) : Zls_Context_Default::get($id, $def);
	}
	public static function setGlobalData($id, $data = null) {
		$set = Z::config()->getHasSwooleContext() && Z::config()->getSwooleUuid() ? '\Zls\Swoole\Context' : 'Zls_Context_Default';
		if (is_array($id)) {
			$prefix = is_string($data) ? $data : '';
			foreach ($id as $k => $v) {
				$set::set($prefix . $k, $v);
			}
		} else {
			$set::set($id, $data);
		}
	}
	public static function header($content = '') {
		self::setGlobalData(ZLS_PREFIX . 'setHeader', array_merge(self::getGlobalData(ZLS_PREFIX . 'setHeader', []), [$content]));
	}
	/**
	 * 设置session配置
	 */
	public static function sessionSet($key = null, $value = null) {
		$id = self::sessionStart();
		$session = self::getGlobalData(ZLS_PREFIX . 'session');
		if (is_array($key)) {
			$session = array_merge($session, $key);
		} else {
			self::arraySet($session, $key, $value);
		}
		self::setGlobalData(ZLS_PREFIX . 'session', $session);
		if (self::isSwoole(true) && ($sessionHandle = self::config()->getSessionHandle())) {
			$sessionHandle->swooleWrite($id, $_SESSION);
		}
	}
	/**
	 * 设置数组
	 */
	public static function arraySet(&$arr, $key, $value, $explode = true) {
		$keys = $explode ? explode('.', $key) : [$key];
		if (1 == count($keys)) {
			$arr[$key] = $value;
			return;
		}
		$a = [];
		$b = $arr;
		while (0 != count($keys)) {
			$k = array_shift($keys);
			$b = isset($b[$k]) ? $b[$k] : [];
			$a[$k] = $b;
		}
		$ka = array_keys($a);
		$a[end($ka)] = $value;
		for ($index = count($ka) - 2; $index >= 0; --$index) {
			$k = $ka[$index];
			$nextK = $ka[$index + 1];
			$a[$k] = [$nextK => $a[$nextK]] + $a[$k];
		}
		$arr[$ka[0]] = $a[$ka[0]];
	}
	/**
	 * 删除/清空指定session
	 */
	public static function sessionUnset($key = null) {
		$id = self::sessionStart();
		$isSwoole = self::isSwoole(true) && ($sessionHandle = self::config()->getSessionHandle());
		$isNull = is_null($key);
		if ($isNull) {
			if (!$isSwoole) {
				session_unset();
			} else {
				$sessionHandle->swooleDestroy($id);
			}
		} else {
			unset($_SESSION[$key]);
			if ($isSwoole) {
				$sessionHandle->swooleWrite($id, $_SESSION);
			}
		}
	}
	/**
	 * 获取原始的POST数据，即php://input获取到的
	 */
	public static function postRaw() {
		return file_get_contents('php://input') ?: self::server('ZLS_POSTRAW');
	}
	public static function postText($key = null, $default = null, $xssClean = true) {
		$input = [];
		$expl = explode("&", self::postRaw());
		foreach ($expl as $r) {
			$tmp = explode("=", $r);
			$v = urldecode(self::arrayGet($tmp, 1, ''));
			$k = preg_replace('/(\[([0-9?])\])/', '.$2', self::arrayGet($tmp, 0, ''));
			if ($k) {
				self::arraySet($input, $k, $v);
			}
		}
		if (is_null($key)) {
			return $input;
		}
		$value = self::arrayGet($input, $key, $default);
		return $xssClean ? self::xssClean($value) : $value;
	}
	public static function postJson($key = null, $default = null, $xssClean = true) {
		$input = @json_decode(self::postRaw(), true);
		if (is_null($key)) {
			return $input;
		}
		$value = self::arrayGet($input, $key, $default);
		return $xssClean ? self::xssClean($value) : $value;
	}
	/**
	 * 获取cookie
	 */
	public static function cookie($key = null, $default = null, $xssClean = false) {
		$key = is_null($key) ? null : self::config()->getCookiePrefix() . $key;
		$value = self::cookieRaw($key, $default, $xssClean);
		return $xssClean ? self::xssClean($value) : $value;
	}
	/**
	 * 设置cookie参数
	 */
	public static function setCookie($key, $value, $life = null, $path = '/', $domian = null, $http_only = false) {
		$key = self::config()->getCookiePrefix() . $key;
		self::setCookieRaw($key, $value, $life, $path, $domian, $http_only);
	}
	/**
	 * 服务器的ip
	 * @return string
	 */
	public static function serverIp() {
		return self::isCli() ? gethostbyname(self::hostname()) : self::server('SERVER_ADDR');
	}
	/**
	 * 服务器的hostname
	 * @return string
	 */
	public static function hostname() {
		return function_exists('gethostname') ? gethostname() : (function_exists('php_uname') ? php_uname('n') : 'unknown');
	}
	/**
	 * 数组扁平化
	 * @return array
	 */
	public static function arrayValues($arr, $key, $default = null, $explode = true, $keepKey = true) {
		return self::arrayMap($arr, function ($value) use ($key, $default, $explode) {
			if (is_array($key)) {
				$result = [];
				foreach ($key as $_key) {
					$result[$_key] = self::arrayGet($value, $_key, $default, $explode);
				}
			} else {
				$result = self::arrayGet($value, $key, $default, $explode);
			}
			return $result;
		}, $keepKey);
	}
	/**
	 * 遍历数组并传递每个值给给定回调
	 *
	 * @param array    $arr
	 * @param Callable $closure
	 * @param bool     $keepKey 保持key值
	 *
	 * @return array
	 */
	public static function arrayMap($arr, callable $closure, $keepKey = true) {
		return $keepKey ? array_map($closure, $arr) : array_map($closure, $arr, array_keys($arr));
	}
	public static function createSqlite3Database($path) {
		return new PDO('sqlite:' . $path);
	}
	/**
	 * 获取缓存数据,不存在则写入
	 *
	 * @param          $key
	 * @param Callable $closure
	 * @param int      $time
	 * @param null     $cacheType
	 *
	 * @return mixed
	 */
	public static function cacheDate($key, callable $closure, $time = 600, $cacheType = null) {
		$data = self::cache($cacheType)->get($key);
		if (!$data) {
			$data = $closure();
			if (!is_null($data)) {
				self::cache($cacheType)->set($key, $data, $time);
			}
		}
		return $data;
	}
	/**
	 * 删除文件夹和子文件夹
	 *
	 * @param string $dirPath     文件夹路径
	 * @param bool   $includeSelf 是否删除最父层文件夹
	 *
	 * @return bool
	 */
	public static function rmdir($dirPath, $includeSelf = true) {
		if (empty($dirPath)) {
			return false;
		}
		$dirPath = self::realPath($dirPath) . '/';
		foreach (scandir($dirPath) as $value) {
			if ('.' == $value || '..' == $value) {
				continue;
			}
			$path = $dirPath . $value;
			if (!is_dir($path)) {
				@unlink($path);
			} else {
				self::rmdir($path);
				@rmdir($path);
			}
		}
		if ($includeSelf) {
			@rmdir($dirPath);
		}
		return true;
	}
	/**
	 * 生成控制器方法的url
	 *
	 * @param string $url     控制器方法
	 * @param array  $getData get传递的参数数组，键值对，键是参数名，值是参数值
	 * @param array  $opt     subfix是否自动添加当前的路由后缀,isHmvc是否自动添加hmvc模块名
	 *
	 * @return string
	 */
	public static function url($url = '', $getData = [], $opt = ['subfix' => true, 'ishmvc' => false]) {
		$config = self::config();
		$route = $config->getRoute();
		$routeType = z::tap($route->getType(), function (&$type) use ($config) {
			if (!$type && ((bool) $getRouters = $config->getRouters())) {
				$routeType = get_class(end($getRouters));
				$config->getRoute()->setType($routeType);
				$type = $routeType;
			}
		});
		if ($routeType) {
			$routeObj = self::factory($routeType, true);
			return $routeObj->url($url, $getData, $opt);
		} else {
			return $url;
		}
	}
	/**
	 * 获取入口文件所在目录url路径。
	 * 只能在web访问时使用，在命令行下面会抛出异常。
	 *
	 * @param null|string $subpath 子路径或者文件路径，如果非空就会被附加在入口文件所在目录的后面
	 * @param bool        $addSlash
	 *
	 * @return string
	 */
	public static function urlPath($subpath = null, $addSlash = false) {
		self::throwIf(self::isCli() && !Z::isSwoole(), 500, 'urlPath() can not be used in cli mode');
		$root = str_replace(['/', '\\'], '/', self::server('DOCUMENT_ROOT', ZLS_PATH));
		chdir($root);
		$root = getcwd();
		$root = str_replace(['/', '\\'], '/', $root);
		$path = self::realPath($subpath, $addSlash, true);
		return preg_replace('|^' . self::realPath($root, $addSlash) . '|', '', $path);
	}
	/**
	 * 获取当前网站域名
	 *
	 * @param bool $prefix
	 * @param bool $uri
	 * @param bool $query
	 *
	 * @return string
	 */
	public static function host($prefix = true, $uri = false, $query = false) {
		$host = '';
		$queryStr = '';
		if (false !== $prefix) {
			$protocol = ('on' == self::server('HTTPS') || 443 == self::server('SERVER_PORT')) ? 'https://' : 'http://';
			$host .= (is_string($prefix)) ? $prefix . self::server('HTTP_HOST') : $protocol . self::server('HTTP_HOST');
		}
		if ((bool) $uri) {
			$path = strstr(self::server('REQUEST_URI'), '?', true) ?: self::server('REQUEST_URI');
			if (!$path) {
				$path = strstr(self::server('SCRIPT_NAME'), ZLS_PATH . '/' . ZLS_INDEX_NAME, true) . self::server(
					'PATH_INFO',
					self::server('REDIRECT_PATH_INFO')
				);
			}
			$host .= $path;
		}
		if (true === $query) {
			$queryStr = self::server('QUERY_STRING') ?: http_build_query(self::get());
		} elseif (is_array($query)) {
			$queryStr = http_build_query($query);
		} elseif (is_string($query)) {
			$queryStr = $query;
		}
		if ($queryStr) {
			$host .= '?' . $queryStr;
		}
		return $host;
	}
	/**
	 * 获取数据
	 *
	 * @param array|null $map 字段映射数组,格式：['表单name名称'=>'表字段名称',...]
	 * @param null       $sourceData
	 * @param bool       $replenish
	 *
	 * @return array []
	 */
	public static function readData(array $map, $sourceData = null, $replenish = true) {
		$data = [];
		$formdata = is_null($sourceData) ? self::post() : $sourceData;
		if ('integer' == gettype(key($map))) {
			$_map = $map;
			$map = [];
			foreach ($_map as $item) {
				$map[$item] = $item;
			}
		}
		if (!$replenish) {
			foreach ($formdata as $formKey => $val) {
				if (self::arrayKeyExists($formKey, $map)) {
					$data[$map[$formKey]] = $val;
				}
			}
		} else {
			foreach ($map as $formKey => $tableKey) {
				if (self::arrayKeyExists($formKey, $formdata)) {
					$data[$tableKey] = $formdata[$formKey];
				} else {
					$data[$tableKey] = '';
				}
			}
		}
		return $data;
	}
	/**
	 * 数据验证
	 *
	 * @param string       $value
	 * @param array|string $rule 如果字符串多个用|分隔
	 * @param null         $db
	 *
	 * @return mixed
	 */
	public static function checkValue($value = '', $rule = [], $db = null) {
		$_err = '';
		$_errKey = '';
		$redata = [];
		$rules = [];
		if (is_string($rule)) {
			$rule = explode('|', $rule);
		}
		foreach ($rule as $k => $v) {
			if (is_int($k)) {
				$rules[$v] = true;
			} else {
				$rules[$k] = $v;
			}
		}
		return z::tap(self::checkData(['value' => $value], ['value' => $rules], $redata, $_err, $_errKey, $db), function ($v) use ($redata, &$value) {
			if ($v) {
				$value = $redata['value'];
			}
		});
	}
	/**
	 * 数据验证
	 *
	 * @param array   $data         需要检验的数据
	 * @param array   $rules        验证规则
	 * @param array  &$returnData   验证通过后，处理过的数据
	 * @param string &$errorMessage 验证失败时的错误信息
	 * @param string &$errorKey     验证失败的时候验证失败的那个key字段名称
	 * @param object &$db           数据库连接对象
	 *
	 * @return mixed
	 * @throws
	 */
	public static function checkData($data = [], $rules = [], &$returnData = null, &$errorMessage = '', &$errorKey = null, &$db = null) {
		static $checkRules;
		if (empty($checkRules)) {
			$defaultRules = (class_exists('\Zls\Action\CheckRules')) ? z::extension('Action\CheckRules')->getRules() : [];
			$userRules = self::config()->getDataCheckRules();
			$checkRules = (!empty($userRules) && is_array($userRules)) ? array_merge(
				$defaultRules,
				$userRules
			) : $defaultRules;
		}
		$getCheckRuleInfo = function ($_rule) {
			$matches = [];
			preg_match('|([^\[]+)(?:\[(.*)\](.?))?|', $_rule, $matches);
			$matches[1] = self::arrayKeyExists(1, $matches) ? $matches[1] : '';
			$matches[3] = !empty($matches[3]) ? $matches[3] : ',';
			$matches[2] = self::arrayKeyExists(2, $matches) ? explode($matches[3], $matches[2]) : [];
			return $matches;
		};
		$returnData = $data;
		foreach ($rules as $key => $keyRules) {
			foreach ($keyRules as $rule => $message) {
				$matches = $getCheckRuleInfo($rule);
				$_v = self::arrayGet($returnData, $key);
				$_r = $matches[1];
				$args = $matches[2];
				if (('function' == $_r) && (is_array($message) ? method_exists($message[0], $message[1]) : (is_callable($message)))) {
					$ruleFunction = $message;
				} elseif (!Z::arrayKeyExists($_r, $checkRules) || !is_callable($checkRules[$_r])) {
					Z::throwIf(true, 500, 'error rule [ ' . $_r . ' ]');
				}
				$db = (is_object($db) && ($db instanceof \Zls_Database_ActiveRecord)) ? $db : Z::db();
				$break = false;
				$returnValue = null;
				$isOkay = false;
				if ('function' == $_r) {
					if (is_array($message)) {
						$errorMessage = call_user_func_array($message, [$key, $_v, $data, $args, &$returnValue, &$break, &$db]);
						$isOkay = !$errorMessage;
					} elseif (is_callable($message) || (is_string($message) && function_exists($message))) {
						$errorMessage = $message($key, $_v, $data, $args, $returnValue, $break, $db);
						$isOkay = !$errorMessage;
					}
				} else {
					$ruleFunction = $checkRules[$_r];
					$isOkay = $ruleFunction($key, $_v, $data, $args, $returnValue, $break, $db);
					$errorMessage = $isOkay ? null : $message;
				}
				if (!$isOkay) {
					$errorKey = $key;
					return false;
				}
				if (!is_null($returnValue)) {
					$returnData[$key] = $returnValue;
				}
				if ($break) {
					break;
				}
			}
		}
		return true;
	}
	public static function extension($className, $shared = true, $args = []) {
		return self::factory('Zls_' . $className, $shared, null, $args);
	}
	/**
	 * 获取数据库操作对象
	 * @staticvar array $instances   数据库单例容器
	 *
	 * @param string|array $group         配置组名称
	 * @param bool         $isNewInstance 是否刷新单例
	 *
	 * @return \Zls_Database_ActiveRecord
	 */
	public static function &db($group = '', $isNewInstance = false) {
		$getDb = function ($group) {
			return new \Zls_Database_ActiveRecord($group);
		};
		if (is_array($group)) {
			$groupString = json_encode($group);
			$key = Z::config()->getSwooleUuid(md5($groupString));
			if (!self::arrayKeyExists($key, self::$dbInstances) || $isNewInstance) {
				$group['group'] = $groupString;
				self::$dbInstances[$key] = $getDb($group);
			}
		} else {
			$config = self::config()->getDatabaseConfig();
			Z::throwIf(empty($config), 'Database', 'database configuration is empty , did you forget to use "->setDatabaseConfig()" in index.php ?');
			if (empty($group)) {
				$group = $config['default_group'];
			}
			$key = Z::config()->getSwooleUuid($group);
			if (!self::arrayKeyExists($key, self::$dbInstances) || $isNewInstance) {
				$config = self::config()->getDatabaseConfig($group);
				Z::throwIf(empty($config), 'Database', 'unknown database config group [ ' . $group . ' ]');
				$config['group'] = $group;
				self::$dbInstances[$key] = $getDb($config);
			}
		}
		return self::$dbInstances[$key];
	}
	public static function getPost($key = null, $default = null, $xssClean = true) {
		if (is_null($key)) {
			$value = self::get() ?: self::post();
		} else {
			$getValue = self::get($key, null, $xssClean);
			$value = is_null($getValue) ? self::post($key, $default, $xssClean) : $getValue;
		}
		return $value;
	}
	/**
	 * 分页方法
	 *
	 * @param int    $total    一共多少记录
	 * @param int    $page     当前是第几页
	 * @param int    $pagesize 每页多少
	 * @param string $url      url是什么，url里面的{page}会被替换成页码
	 * @param int    $a_count  分页条中页码链接的总数量,不包含当前页
	 *
	 * @return array $result
	 */
	public static function page($total, $page = 1, $pagesize = 10, $url = '{page}', $a_count = 6) {
		$a_num = ($a_count > 0) ? $a_count : 10;
		$a_num = 0 == $a_num % 2 ? $a_num + 1 : $a_num;
		$pages = ceil($total / $pagesize);
		$curpage = (int) $page ?: 1;
		$curpage = $curpage > $pages || $curpage <= 0 ? 1 : $curpage;
		$start = $curpage - ($a_num - 1) / 2;
		$end = $curpage + ($a_num - 1) / 2;
		$start = $start <= 0 ? 1 : $start;
		$end = $end > $pages ? $pages : $end;
		if ($pages >= $a_num) {
			if ($curpage <= ($a_num - 1) / 2) {
				$end = $a_num;
			}
			if ($end - $curpage <= ($a_num - 1) / 2) {
				$start -= floor($a_num / 2) - ($end - $curpage);
			}
		}
		$result = [
			'pages' => [],
			'total' => $total,
			'count' => $pages,
			'curpage' => $curpage,
			'prefix' => (int) (1 == $curpage ? '' : str_replace('{page}', $curpage - 1, $url)),
			'start' => (int) (str_replace('{page}', 1, $url)),
			'end' => (int) (str_replace('{page}', $pages, $url)),
			'subfix' => (int) (($curpage == $pages || 0 == $pages) ? '' : str_replace('{page}', $curpage + 1, $url)),
		];
		for ($i = $start; $i <= $end; ++$i) {
			$result['pages'][$i] = str_replace('{page}', $i, $url);
		}
		return $result;
	}
	public static function json() {
		$args = func_get_args();
		$handle = self::config()->getOutputJsonRender();
		if (is_callable($handle)) {
			return call_user_func_array($handle, $args);
		} else {
			return '';
		}
	}
	/**
	 * 重定向
	 */
	public static function redirect($url, $msg = null, $time = 3, $view = null) {
		if (self::isSwoole(true)) {
			z::di()->makeShared(SWOOLE_RESPONSE)->status(302);
		}
		if (empty($msg) && empty($view)) {
			self::header('Location: ' . $url);
		} else {
			$time = intval($time) ? intval($time) : 3;
			self::header("refresh:{$time};url={$url}"); //单位秒
			if (!empty($view)) {
				$msg = self::view()->set(['msg' => $msg, 'url' => $url, 'time' => $time])->load($view);
			}
		}
		self::end($msg);
	}
	public static function finish($msg = '') {
		self::end($msg);
	}
	public static function message($msg, $url = null, $time = 3, $view = null) {
		$time = intval($time) ? intval($time) : 3;
		if (!empty($url)) {
			self::header("refresh:{$time};url={$url}"); //单位秒
		}
		if (!empty($view)) {
			$msg = self::view()->set(['msg' => $msg, 'url' => $url, 'time' => $time])->load($view);
		}
		self::end($msg);
	}
	public static function __callStatic($name, $arguments) {
		if (self::strBeginsWith($name, 'is') && ($rName = strtoupper(substr($name, 2))) && in_array($rName, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'COPY', 'HEAD', 'OPTIONS', 'LINK', 'UNLINK', 'PURGE'])) {
			return $rName === strtoupper(Z::server('REQUEST_METHOD', ''));
		} elseif (self::strEndsWith($name, 'Get') && ($rName = substr($name, 0, -3))) {
			$data = self::getGlobalData(ZLS_PREFIX . $rName);
			$key = self::arrayGet($arguments, 0);
			return $key ? self::arrayGet($data, $key) : $data;
		}
		$methods = self::config()->getZMethods();
		self::throwIf(empty($methods[$name]), 500, $name . ' not found in ->setZMethods() or it is empty');
		if (is_string($methods[$name])) {
			$className = $methods[$name] . '_' . self::arrayGet($arguments, 0);
			self::throwIf(!$className, 500, $name . $methods[$name] . '() need argument of class name ');
			return self::factory($className);
		} elseif (is_callable($methods[$name])) {
			return call_user_func_array($methods[$name], $arguments);
		} else {
			self::throwIf(true, 500, $name . ' unknown type of method [ ' . $name . ' ]');
		}
		return false;
	}
	/**
	 * 加密
	 */
	public static function encrypt($str, $key = '', $attachKey = '') {
		if (!$str) {
			return '';
		}
		$iv = $key = substr(md5(self::getEncryptKey($key, $attachKey)), 0, 16);
		$blockSize = 16;
		$msgLength = strlen($str);
		if (0 != $msgLength % $blockSize) {
			$str .= str_repeat("\0", $blockSize - ($msgLength % $blockSize));
		}
		return bin2hex(openssl_encrypt($str, 'AES-128-CBC', $key, OPENSSL_NO_PADDING, $iv));
	}
	private static function getEncryptKey($key, $attachKey) {
		$_key = $key ? $key : self::config()->getEncryptKey();
		self::throwIf(!$key && !$_key, 500, 'encrypt key can not empty or you can set it in index.php : ->setEncryptKey()');
		return $_key . $attachKey;
	}
	/**
	 * 解密
	 */
	public static function decrypt($str, $key = '', $attachKey = '') {
		if (!$str) {
			return '';
		}
		$iv = $key = substr(md5(self::getEncryptKey($key, $attachKey)), 0, 16);
		return trim(@openssl_decrypt(hex2bin($str), 'AES-128-CBC', $key, OPENSSL_NO_PADDING, $iv), "\0") ?: false;
	}
	/**
	 * 检测ip是否在白名单内
	 *
	 * @param      $clientIp
	 * @param null $whitelist
	 *
	 * @return bool
	 * @internal param string $ip
	 */
	public static function isWhiteIp($clientIp, $whitelist = null) {
		$config = self::config();
		if (!is_array($whitelist)) {
			$whitelist = $config->getMaintainIpWhitelist();
		}
		$isWhite = false;
		$clientIpLong = ip2long($clientIp);
		foreach ($whitelist as $ip) {
			$info = explode('/', $ip);
			$netmask = empty($info[1]) ? '32' : $info[1];
			$ipinfo = self::ipInfo($info[0] . '/' . $netmask);
			if (($ipinfo['netaddress'] === $clientIp) || ($ipinfo['count'] > 0 && ($clientIpLong >= ip2long($ipinfo['start']) && $clientIpLong <= ip2long($ipinfo['end'])))) {
				$isWhite = true;
				break;
			}
		}
		return $isWhite;
	}
	/**
	 * 获取IP段信息
	 *
	 * @param string $ipAddr
	 * @param string $key
	 *
	 * @return array
	 *               $ipAddr格式：192.168.1.10/24、192.168.1.10/32
	 *               传入Ip地址对Ip段地址进行处理得到相关的信息
	 *               没有$key时，返回数组：array(,netmask=>网络掩码,count=>网络可用IP数目,start=>可用IP开始,end=>可用IP结束,netaddress=>网络地址,broadcast=>广播地址,)
	 *               有$key时返回$key对应的值，$key是上面数组的键。
	 */
	public static function ipInfo($ipAddr, $key = null) {
		$ipAddr = str_replace(' ', '', $ipAddr);
		$arr = explode('/', $ipAddr);
		$ipAddr = $arr[0];
		$ipAddrArr = explode('.', $ipAddr);
		foreach ($ipAddrArr as $k => $v) {
			$ipAddrArr[$k] = intval($v);
		}
		$ipAddr = join('.', $ipAddrArr);
		$netbits = intval((self::arrayKeyExists(1, $arr) ? $arr[1] : 0));
		$subnetMask = long2ip(ip2long('255.255.255.255') << (32 - $netbits));
		$ip = ip2long($ipAddr);
		$nm = ip2long($subnetMask);
		$nw = ($ip & $nm);
		$bc = $nw | (~$nm);
		$ips = [];
		$ips['netmask'] = long2ip($nm);
		$ips['count'] = ($bc - $nw - 1);
		if ($ips['count'] <= 0) {
			$ips['count'] += 4294967296;
		}
		if (32 == $netbits) {
			$ips['count'] = 0;
			$ips['start'] = long2ip($ip);
			$ips['end'] = long2ip($ip);
		} else {
			$ips['start'] = long2ip($nw + 1);
			$ips['end'] = long2ip($bc - 1);
		}
		$bc = sprintf('%u', $bc);
		$nw = sprintf('%u', $nw);
		$ips['netaddress'] = long2ip((int) $nw);
		$ips['broadcast'] = long2ip((int) $bc);
		return is_null($key) ? $ips : $ips[$key];
	}
	/**
	 * 判断是否是ajax请求，只对xmlhttprequest的ajax请求有效
	 * @return bool
	 */
	public static function isAjax() {
		return 'xmlhttprequest' == strtolower(self::server('HTTP_X_REQUESTED_WITH', ''));
	}
}
class Zls {
	public static $loadedModules = [];
	public static $zlsTime;
	public static $zlsMemory = false;
	/**
	 * 包类库自动加载器
	 *
	 * @param string $className
	 *
	 * @return bool
	 */
	public static function classAutoloader($className) {
		$className = str_replace(['/', '_'], '\\', $className);
		$config = self::getConfig();
		$className = str_replace(['Hmvc\\', 'Packages\\'], '', $className);
		$classPath = $config->getClassesDirName() . '/' . str_replace('\\', '/', $className) . '.php';
		$alias = $config->getAlias();
		if (isset($alias[$className])) {
			return class_alias($alias[$className], $className);
		} else {
			foreach ($config->getPackages() as $path) {
				if (file_exists($filePath = $path . $classPath)) {
					Z::includeOnce($filePath);
					return true;
				}
			}
		}
		return false;
	}
	/**
	 * 获取运行配置
	 * @return Zls_Config
	 */
	public static function &getConfig() {
		static $zlsConfig;
		if (!$zlsConfig) {
			$zlsConfig = new \Zls_Config();
		}
		return $zlsConfig;
	}
	/**
	 * 初始化框架配置
	 *
	 * @param string $timeZone
	 *
	 * @return \Zls_Config
	 */
	public static function initialize($timeZone = 'PRC') {
		date_default_timezone_set($timeZone);
		$config = self::getConfig();
		if (function_exists('__autoload')) {
			spl_autoload_register('__autoload');
		}
		spl_autoload_register(['Zls', 'classAutoloader']);
		Z::setGlobalData([
			'server' => $_SERVER,
			'get' => $_GET,
			'post' => $_POST,
			'files' => isset($_FILES) ? $_FILES : [],
			'cookie' => isset($_COOKIE) ? $_COOKIE : [],
			'session' => isset($_SESSION) ? $_SESSION : [],
		], ZLS_PREFIX);
		$config->setAppDir(ZLS_APP_PATH);
		$config->addPackage(ZLS_APP_PATH);
		$config->composer();
		$config->runState = false;
		$config->hasSwooleContext = class_exists('\Zls\Swoole\Context');
		return $config;
	}
	/**
	 * 运行调度
	 */
	public static function run() {
		$config = Zls::getConfig();
		$config->runState = true;
		if (!in_array($config->getRequest()->getPathInfo(), ['/favicon.ico'], true)) {
			$exceptionLevel = $config->getExceptionLevel();
			error_reporting(empty($exceptionLevel) ? E_ALL ^ E_DEPRECATED : $config->getExceptionLevel());
			$loggerDispatcher = $isException = null;
			if ($config->getExceptionControl()) {
				$loggerDispatcher = Zls_Logger_Dispatcher::initialize();
			}
			try {
				if (Z::isCli() && !Z::isSwoole()) {
					//todo 去掉is
					self::runCli();
				} elseif (Z::isPluginMode()) {
					self::runSetData(function () {
						self::runPlugin();
					});
				} else {
					self::runSetData(function () {
						self::initSession();
						self::getConfig()->bootstrap();
						echo self::resultException(function () {
							return self::runWeb();
						});
					});
				}
			} catch (Zls_Exception_Exit $e) {
				self::runSetData(function () use ($e) {
					echo $e->getMessage();
				});
			} catch (\Exception $e) {
				$isException = $e;
			} catch (\Throwable $e) {
				$isException = $e;
			}
			if ($isException) {
				if ($loggerDispatcher) {
					echo Zls_Logger_Dispatcher::dispatch(new Zls_Exception_500($isException->getMessage(), 500, 'Error', $isException->getFile(), $isException->getLine()), true);
				} else {
					throw $isException;
				}
			}
			Z::eventEmit(ZLS_PREFIX . 'DEFER');
		}
		return true;
	}
	public static function runSetData(callable $fn) {
		ob_start();
		$fn();
		$content = ob_get_clean();
		$hs = Z::getGlobalData(ZLS_PREFIX . 'setHeader', []);
		foreach ($hs as $c) {
			header($c);
		}
		$cs = Z::getGlobalData(ZLS_PREFIX . 'setCookie', []);
		foreach ($cs as $c) {
			setcookie(...$c);
		}
		if ($content !== "") {
			echo $content;
		}
	}
	public static function resultException(callable $fn) {
		$exception = null;
		try {
			return $fn();
		} catch (\Zls_Exception $e) {
			return Zls_Logger_Dispatcher::dispatch($e, true);
		} catch (\Zls_Exception_Exit $e) {
			return $e->getMessage();
		} catch (\Exception $e) {
		} catch (\Throwable $e) { // php 7
		}
		return Zls_Logger_Dispatcher::dispatch(new Zls_Exception_500($e->getMessage(), 500, 'Error', $e->getFile(), $e->getLine()), true);
	}
	/**
	 * 命令行模式运行
	 */
	private static function runCli() {
		self::initDebug();
		if (!ZLS_RUN_MODE_CLI) {
			return false;
		}
		$executes = [];
		$args = Z::getOpt();
		$hmvcModuleName = Z::arrayGet($args, 'hmvc');
		$current = strtolower(current(array_slice(array_keys($args), 1)));
		$isTask = ('task' === $current);
		$activity = $isTask ? str_replace('/', '_', Z::arrayGet($args, 'task')) : Z::arrayGet($args, 1);
		if (!empty($hmvcModuleName)) {
			self::checkHmvc($hmvcModuleName);
		}
		$taskObject = null;
		try {
			if ($isTask) {
				if ($activity === '1') {
					Z::end('Execute a task, please use -task <taskName> (php zls -task xxx)' . PHP_EOL);
				}
				$taskName = Zls::getConfig()->getTaskDirName() . '_' . $activity;
				$taskObject = z::factory($taskName, true);
				Z::throwIf(!($taskObject instanceof Zls_Task), 500, '[ ' . $taskName . ' ] not a valid Zls_Task', 'ERROR');
				$executes = ['_execute'];
			} else {
				$command = new Zls_Command($args);
				$taskObject = $command->instance();
				$executes = $command->executes();
			}
		} catch (\Zls_Exception_500 $e) {
			Z::end($e->getMessage());
		}
		if (!$executes) {
			$executes = ['execute'];
		}
		foreach ($executes as $execute) {
			//}
			$taskObject->$execute($args);
		}
	}
	public static function initDebug() {
		self::$zlsMemory = function_exists('memory_get_usage') ? memory_get_usage() : false;
		self::$zlsTime = Z::microtime();
	}
	/**
	 * 检测并加载hmvc模块,成功返回模块文件夹名称，失败返回false或抛出异常
	 *
	 * @param string $hmvcModuleName hmvc模块在URI中的名称，即注册配置hmvc模块数组的键名称
	 * @param bool   $throwException
	 *
	 * @return bool
	 */
	public static function checkHmvc($hmvcModuleName, $throwException = true) {
		if (!empty($hmvcModuleName)) {
			$config = Zls::getConfig();
			$hmvcModules = $config->getHmvcModules();
			if (empty($hmvcModules[$hmvcModuleName])) {
				Z::throwIf($throwException, 500, 'Hmvc Module [ ' . $hmvcModuleName . ' ] not found, please check your config.', 'ERROR');
				return false;
			}
			$hmvcModuleDirName = $hmvcModules[$hmvcModuleName];
			if (!Z::arrayKeyExists($hmvcModuleName, self::$loadedModules)) {
				self::$loadedModules[$hmvcModuleName] = 1;
				$hmvcModulePath = $config->getAppDir() . $config->getHmvcDirName() . '/' . $hmvcModuleDirName . '/';
				$config->setAppDir($hmvcModulePath)->addMasterPackage($hmvcModulePath)->bootstrap();
			}
			return $hmvcModuleDirName;
		}
		return false;
	}
	/**
	 * 插件模式运行
	 */
	private static function runPlugin() {
		self::initDebug();
	}
	public static function initSession($id = null) {
		$sessionConfig = self::getConfig()->getSessionConfig();
		$sessionHandle = self::getConfig()->getSessionHandle();
		$haveSessionHandle = $sessionHandle && $sessionHandle instanceof Zls_Session;
		@ini_set('session.auto_start', 0);
		@ini_set('session.gc_probability', 1);
		@ini_set('session.gc_divisor', 100);
		@ini_set('session.gc_maxlifetime', $sessionConfig['lifetime']);
		@ini_set('session.cookie_lifetime', $sessionConfig['lifetime']);
		@ini_set('session.referer_check', '');
		@ini_set('session.entropy_file', '/dev/urandom');
		@ini_set('session.entropy_length', 16);
		@ini_set('session.use_cookies', 1);
		@ini_set('session.use_only_cookies', 1);
		@ini_set('session.use_trans_sid', 0);
		@ini_set('session.hash_function', 1);
		@ini_set('session.hash_bits_per_character', 5);
		session_cache_limiter('nocache');
		session_set_cookie_params($sessionConfig['lifetime'], $sessionConfig['cookie_path'], preg_match('/^[^.]+$/', Z::server('HTTP_HOST')) ? '' : $sessionConfig['cookie_domain']);
		if (!empty($sessionConfig['session_save_path'])) {
			session_save_path($sessionConfig['session_save_path']);
		}
		session_name($sessionConfig['session_name']);
		register_shutdown_function('session_write_close');
		if ($haveSessionHandle) {
			$sessionHandle->init(session_id());
		}
		return $sessionConfig['autostart'] ? Z::sessionStart($id) : false;
	}
	/**
	 * web模式运行
	 * @throws Zls_Exception_Exit
	 */
	public static function runWeb() {
		self::initDebug();
		$config = Z::config();
		$contents = null;
		$_apiDoc = (isset($_GET['_api']) && (bool) $config->getApiDocToken() && (Z::get('_token', '', true) === $config->getApiDocToken()) && class_exists('\Zls\Action\ApiDoc'));
		$config = self::getConfig();
		$class = '';
		$method = '';
		foreach ($config->getRouters() as $router) {
			$route = $router->find();
			$routeClass = get_class($router);
			if (is_object($route) && $route->found()) {
				$config->setRoute($route);
				$route->setType($routeClass);
				$class = $route->getController();
				$method = $route->getMethod();
				break;
			} elseif (is_string($route) || is_int($route) || is_null($route)) {
				$config->getRoute()->setType($routeClass);
				$contents = $route;
				break;
			} elseif ($route === true) {
				$contents = '';
			} else {
				Z::throwIf(is_array($route), 500, 'Cannot output array type, please use "return Z::view()->load($view, $data)"');
			}
		}
		if ($config->getIsMaintainMode()) {
			$isWhite = Z::isWhiteIp(Z::clientIp());
			if (!$isWhite) {
				$handle = $config->getMaintainModeHandle();
				if (is_object($handle)) {
					Z::end($handle->handle());
				}
			}
		}
		!is_null($contents) && Z::end((string) $contents);
		Z::throwIf(empty($route), 500, 'none router was found in configuration', 'ERROR');
		$_route = $config->getRoute();
		if ($hmvcModuleName = (null !== $_route->getHmvcModuleName()) ? $_route->getHmvcModuleName() : $config->getCurrentDomainHmvcModuleNname()) {
			if (Zls::checkHmvc($hmvcModuleName, false)) {
				$_route->setHmvcModuleName($hmvcModuleName);
				$_route->setFound(true);
			}
		} else {
			$_route->setHmvcModuleName(false);
		}
		if (empty($class)) {
			$class = $config->getControllerDirName() . '_' . $config->getDefaultController();
			$_route->setController($class);
		}
		if (empty($method)) {
			$method = $config->getMethodPrefix() . $config->getDefaultMethod();
			$_route->setMethod($method);
		}
		$config->setRoute($_route);
		$contents = !$_apiDoc ? $config->getSeparationRouter($config->getRoute()->getController(), $config->getRoute()->getHmvcModuleName()) : null;
		if (!$contents) {
			if ((bool) $_apiDoc) {
				/**
				 * @var \Zls\Action\ApiDoc
				 */
				$docComment = Z::extension('Action\ApiDoc');
				if ('self' == $_GET['_api']) {
					$docComment::html(
						'self',
						$docComment::apiMethods($docComment::getClassName($class), $method, true, $config->getRoute()->gethmvcModuleName())
					);
				} else {
					if ('all' == $_GET['_api']) {
						$docComment::html('parent', $docComment::all());
					} else {
						$docComment::html(
							'parent',
							$docComment::docComment($docComment::getClassName($_route->getController()), $config->getRoute()->gethmvcModuleName())
						);
					}
				}
				return '';
			}
			$contents = Z::controller($class, substr($method, strlen($config->getMethodPrefix())), $_route->getArgs(), null, true, true);
			// $contents = self::resultException(function () use ($class, $method, $config, $_route) {
			//     });
			// });
		}
		return $contents;
	}
}
class Zls_Pipeline {
	private $req;
	public $useAspect = false;
	static private $classObj = [];
	public function setAspect($status) {
		$this->useAspect = $status;
		return $this;
	}
	public function send($req) {
		$this->req = $req;
		return $this;
	}
	public function then(array $middlewares, callable $destination) {
		$pipeline = array_reduce(array_reverse($middlewares), $this->carry(), $destination);
		return $pipeline($this->req);
	}
	public function carry() {
		return function ($stack, $pipe) {
			return function ($req = []) use ($stack, $pipe) {
				$slice = $this->baseCarry();
				$callable = $slice($stack, $pipe);
				return $callable($req);
			};
		};
	}
	public function drawReq(array $req) {
		$currentClass = $req['originalClass'];
		$currentMethodType = null;
		$currentData = explode(Zls::getConfig()->getMethodPrefix(), strtolower($req['methodFull']));
		$currentMethod = $currentData[1];
		$currentMethodType = $currentData[0] ?: null;
		return [$currentClass, $currentMethod, $currentMethodType];
	}
	public function drawClass($classes) {
		$method = $type = null;
		if (strpos($classes, '@')) {
			$classeData = explode('@', $classes);
			if (count($classeData) < 3) {
				list($class, $method) = $classeData;
			} else {
				list($class, $method, $type) = $classeData;
				$type = (($type = strtolower($type)) && $type === 'get') ? null : $type;
			}
		} else {
			$class = $classes;
		}
		return [$class, strtolower($method), $type];
	}
	private function whetherItMatches($str, $key) {
		if ($key === $str) {
			return true;
		}
		$p = strpos($str, '*');
		return $p > 0 ? Z::strBeginsWith($key, substr($str, 0, $p)) : false;
	}
	public function aspect(Zls_Middleware $obj, callable $stack, array $classes, array $req) {
		$current = $this->drawReq($req);
		foreach ($classes as $_class) {
			$ClassData = $this->drawClass($_class);
			list($class, $method, $type) = $ClassData;
			$eqClass = $this->whetherItMatches($class, $current[0]);
			if (!$eqClass) {
				continue;
			}
			switch (true) {
			case $ClassData === $current:
			case !$method:
			case ($methodOk = $this->whetherItMatches($method, $current[1])):
			case is_null($type) && $methodOk:
			case (is_null($current[2]) || $current[2] === $type) && $methodOk:
				return $obj->handle($req, $stack);
			}
		}
		return $stack($req);
	}
	public function baseCarry() {
		return function ($stack, $pipe) {
			return function ($req) use ($stack, $pipe) {
				try {
					if (!is_callable($pipe)) {
						$className = md5($pipe);
						if (isset(self::$classObj[$className])) {
							$obj = self::$classObj[$className];
						} else {
							$obj = Z::factory($pipe);
							Z::throwIf(!($obj instanceof Zls_Middleware), 500, '[ ' . $pipe . ' ] not a valid Zls_Middleware', 'ERROR');
							self::$classObj[$className] = $obj;
						}
						if ($classes = $obj->classes($req)) {
							return $this->aspect($obj, $stack, $classes, $req);
						} else {
							return $obj->handle($req, $stack);
						}
					} else {
						return $pipe($req, $stack);
					}
				} catch (\Zls_Exception_Exit $e) {
					return $e->getMessage();
				}
			};
		};
	}
}
class Zls_Command {
	private $command;
	private $executes;
	public function __construct($args) {
		if ('artisan' === z::arrayGet($args, 1)) {
			$args = array_values(array_diff($args, ['artisan']));
		}
		$first = z::arrayGet($args, 1);
		$config = Z::config();
		$taskObject = '';
		$commandMain = '\Zls\Command\Main';
		if (!class_exists($commandMain)) {
			Z::end('Warning: command not installed, Please install "composer require zls/command"' . PHP_EOL);
		}
		$defaultCmd = 'Main';
		$argsCommandName = $first ?: $defaultCmd;
		$name = $argsCommandName;
		$command = explode(':', $name);
		$name = array_shift($command);
		$executes = $command;
		$commandLists = $config->getCommands();
		if ($name === $defaultCmd) {
			$commandName = $commandMain;
		} elseif (Z::arrayKeyExists($name, $commandLists)) {
			$commandName = $commandLists[$name];
		} else {
			$name = ucfirst($name);
			$commandName = 'Command_' . $name;
		}
		try {
			$taskObject = z::factory($commandName, true);
		} catch (\Zls_Exception_500 $e) {
			$err = $e->getMessage();
			$errSub = 'not found';
			Z::throwIf(!Z::strEndsWith($err, $errSub), 500, $err);
			try {
				$taskObject = z::factory('\\Zls\\Command\\' . $name, true);
			} catch (\Zls_Exception_500 $e) {
				$err = $e->getMessage();
				Z::throwIf(!Z::strEndsWith($err, $errSub), 500, $err);
				Z::end("Command { {$name} } is not defined" . PHP_EOL);
			}
		}
		$this->command = $taskObject;
		if (Z::arrayGet($args, ['h', 'H', '-help']) && $commandName !== $commandMain) {
			$executes = ['help'];
		}
		foreach ($executes as $execute) {
			Z::throwIf(!method_exists($taskObject, $execute) && !method_exists($taskObject, '__call'), 500, "Command { {$name} } is not { {$execute} } handle");
		}
		$this->executes = $executes;
	}
	public function instance() {
		return $this->command;
	}
	public function executes() {
		return $this->executes;
	}
}
class Zls_Di {
	protected $_instances = [];
	protected $_service = [];
	protected $appDir;
	public function merge(Zls_Di $di) {
		$list = $di->lists();
		$this->_service = array_merge($list['service'], $this->_service);
		$this->_instances = array_merge($list['instances'], $this->_instances);
	}
	public function lists() {
		return ['service' => $this->_service, 'instances' => $this->_instances];
	}
	public function bind($name, $definition) {
		$this->_service[$name] = $definition;
	}
	public function remove($name = null) {
		if (!is_null($name)) {
			unset($this->_service[$name]);
			foreach ($this->_instances as $k => $v) {
				if (z::strBeginsWith($k, $name . ':')) {
					unset($this->_instances[$k]);
				}
			}
		} else {
			$this->_service = [];
			$this->_instances = [];
		}
	}
	public function makeShared($name, $args = []) {
		$original = $name;
		if (!!$args) {
			$name = $name . ':' . json_encode($args);
		}
		if (!isset($this->_instances[$name])) {
			$this->_instances[$name] = $this->factory($original, $args);
		}
		return $this->_instances[$name];
	}
	private function factory($name, $args = []) {
		$contain = isset($this->_service[$name]);
		Z::throwIf(!$contain, 500, 'Service [' . $name . '] wasn\'t found in the dependency injection container', 'ERROR');
		$definition = $this->_service[$name];
		if (is_array($definition)) {
			if (Z::isPluginMode()) {
				$this->appDir = Z::config()->getAppDir();
				Zls::checkHmvc($definition['hmvc']);
			}
			$definition = $definition['class'];
		}
		if (!is_callable($definition)) {
			$definition = $this->getClosure($definition, (array) $args);
		}
		return call_user_func_array($definition, $args);
	}
	private function getClosure($definition, $args) {
		return function () use ($definition, $args) {
			if (!is_object($definition)) {
				$classNameFn = function ($definition) {
					$className1 = str_replace(['\\', '/'], '_', $definition);
					$className2 = str_replace(['/', '_'], '\\', $definition);
					return class_exists($className1) ? $className1 : (class_exists($className2) ? $className2 : '');
				};
				if (!$className = $classNameFn($definition)) {
					preg_match('/Hmvc.?(.*)/i', $definition, $match);
					$newDefinition = Z::arrayGet($match, 1, $definition);
					if (!$className = $classNameFn($newDefinition)) {
						$className = $classNameFn('Packages_' . $newDefinition);
					}
				}
				Z::throwIf(!$className, 500, 'class [ ' . $definition . ' ] not found', 'ERROR');
				$closure = null;
				if (!count($args)) {
					$closure = new $className();
				} else {
					$closure = new $className(...$args);
				}
			} else {
				$closure = clone $definition;
			}
			if ($this->appDir) {
				Z::config()->setAppDir($this->appDir);
				$this->appDir = null;
			}
			return $closure;
		};
	}
	public function thing($name) {
		return $this->has($name) ? $this->_service[$name] : [];
	}
	public function make($name, $args = []) {
		return $this->factory($name, $args);
	}
	public function has($name) {
		return isset($this->_service[$name]);
	}
}
class Zls_PDO extends PDO {
	protected $transactionCounter = 0;
	private $isLast;
	public function isInTransaction() {
		return !$this->isLast;
	}
	public function beginTransaction() {
		if (!$this->transactionCounter++) {
			return parent::beginTransaction();
		}
		$this->exec('SAVEPOINT trans' . $this->transactionCounter);
		return $this->transactionCounter >= 0;
	}
	public function commit() {
		if (!--$this->transactionCounter) {
			$this->isLast = true;
			return parent::commit();
		}
		$this->isLast = false;
		return $this->transactionCounter >= 0;
	}
	public function rollback() {
		if (--$this->transactionCounter) {
			$this->exec('ROLLBACK TO trans' . ($this->transactionCounter + 1));
			return true;
		}
		return parent::rollback();
	}
}
class Zls_Database_ActiveRecord extends Zls_Database {
	public $arFrom;
	protected $_lastInsertBatchCount = 0;
	private $arSelect;
	private $arJoin;
	private $arWhere;
	private $arGroupby;
	private $arHaving;
	private $arLimit;
	private $arOrderby;
	private $arSet;
	private $primaryKey;
	private $arUpdateBatch;
	private $arInsert;
	private $arInsertBatch;
	private $_asTable;
	private $_asColumn;
	private $_values;
	private $_sqlType;
	private $_currentSql;
	public function __construct(array $config = []) {
		parent::__construct($config);
		$this->_reset();
	}
	protected function _reset() {
		$this->arSelect = [];
		$this->arFrom = [];
		$this->arJoin = [];
		$this->arWhere = [];
		$this->arGroupby = [];
		$this->arHaving = [];
		$this->arOrderby = [];
		$this->arLimit = '';
		$this->primaryKey = '';
		$this->arSet = [];
		$this->arUpdateBatch = [];
		$this->arInsert = [];
		$this->arInsertBatch = [];
		$this->_asTable = [];
		$this->_asColumn = [];
		$this->_values = [];
		$this->_sqlType = 'select';
		$this->_currentSql = '';
	}
	/**
	 * 展示字段
	 *
	 * @param      $select
	 * @param bool $wrap
	 *
	 * @return $this
	 */
	public function select($select, $wrap = true) {
		$selectArr = is_array($select) ? $select : explode(',', $select);
		foreach ($selectArr as $key) {
			$this->arSelect[] = [$key, $wrap];
		}
		return $this;
	}
	/**
	 * 联表
	 *
	 * @param        $table
	 * @param        $on
	 * @param string $type
	 *
	 * @return $this
	 */
	public function join($table, $on, $type = '') {
		$this->arJoin[] = [$table, $on, strtoupper($type)];
		return $this;
	}
	/**
	 * 分组
	 *
	 * @param $key
	 *
	 * @return $this
	 */
	public function groupBy($key) {
		$key = explode(',', $key);
		foreach ($key as $k) {
			$this->arGroupby[] = trim($k);
		}
		return $this;
	}
	/**
	 * 结果过滤
	 *
	 * @param        $having
	 * @param string $leftWrap
	 * @param string $rightWrap
	 *
	 * @return $this
	 */
	public function having($having, $leftWrap = 'AND', $rightWrap = '') {
		$this->arHaving[] = [$having, $leftWrap, $rightWrap, count($this->arHaving)];
		return $this;
	}
	/**
	 * 排序
	 *
	 * @param        $key
	 * @param string $type
	 *
	 * @return $this
	 */
	public function orderBy($key, $type = 'desc') {
		$this->arOrderby[$key] = $type;
		return $this;
	}
	/**
	 * 结果数量
	 *
	 * @param $offset
	 * @param $count
	 *
	 * @return $this
	 */
	public function limit($offset, $count = null) {
		if (is_null($count)) {
			$count = $offset;
			$offset = 0;
		}
		$this->arLimit = "$offset , $count";
		return $this;
	}
	/**
	 * 插入数据
	 *
	 * @param       $table
	 * @param array $data
	 *
	 * @return $this
	 */
	public function insert($table, array $data) {
		$this->_sqlType = 'insert';
		$this->arInsert = $data;
		$this->_lastInsertBatchCount = 0;
		$this->from($table);
		return $this;
	}
	/**
	 * 查询表
	 *
	 * @param string|array|Callable $from
	 * @param string                $as 别名
	 * @param bool                  $wrap
	 *
	 * @return $this
	 */
	public function from($from, $as = '', $wrap = true) {
		if (is_array($from)) {
			$as = current($from);
			if (!$from = key($from)) {
				$from = $as;
				$as = '';
			}
		} elseif (is_callable($from)) {
			$_db = $this->cloneDb();
			$from($_db);
			$from = ' (' . $_db->getSql() . ') ';
			$values = $_db->getSqlValues();
			foreach ($values as $value) {
				array_push($this->_values, $value);
			}
			if (!$as) {
				$as = '_tmp' . md5($from);
			}
		}
		$this->arFrom = [$from, $as, $wrap];
		if ($as) {
			$this->_asTable[$as] = 1;
		}
		return $this;
	}
	public function cloneDb() {
		return clone $this;
	}
	/**
	 * 获取sql语句
	 * @return mixed|string
	 */
	public function getSql() {
		if ($this->_currentSql) {
			return $this->_currentSql;
		}
		switch ($this->_sqlType) {
		case 'select':
			$this->_currentSql = $this->_getSelectSql();
			break;
		case 'update':
			$this->_currentSql = $this->_getUpdateSql();
			break;
		case 'updateBatch':
			$this->_currentSql = $this->_getUpdateBatchSql();
			break;
		case 'insert':
			$this->_currentSql = $this->_getInsertSql();
			break;
		case 'insertBatch':
			$this->_currentSql = $this->_getInsertBatchSql();
			break;
		case 'replace':
			$this->_currentSql = $this->_getReplaceSql();
			break;
		case 'replaceBatch':
			$this->_currentSql = $this->_getReplaceBatchSql();
			break;
		case 'delete':
			$this->_currentSql = $this->_getDeleteSql();
			break;
		default:
		}
		return $this->_currentSql;
	}
	private function _getSelectSql() {
		$from = $this->_getFrom();
		$where = $this->_getWhere();
		$having = '';
		foreach ($this->arHaving as $w) {
			$having .= call_user_func_array([$this, '_compileWhere'], $w);
		}
		$having = trim($having);
		if ($having) {
			$having = "\n" . ' HAVING ' . $having;
		}
		$groupBy = trim($this->_compileGroupBy());
		if ($groupBy) {
			$groupBy = "\n" . ' GROUP BY ' . $groupBy;
		}
		$orderBy = trim($this->_compileOrderBy());
		if ($orderBy) {
			$orderBy = "\n" . ' ORDER BY ' . $orderBy;
		}
		$limit = $this->_getLimit();
		$select = $this->_compileSelect();
		if ($this->_isSqlsrv() && (bool) $limit) {
			$limitArg = explode(',', $limit);
			if (count($limitArg) > 1) {
				$offset = (int) $limitArg[1];
				$limit = (int) $limitArg[0];
				if ((bool) $orderBy) {
					$orderBy = $orderBy . ' OFFSET ' . $limit . ' ROWS FETCH NEXT ' . $offset . '  ROWS ONLY ';
				} else {
					if ($limit > 0) {
						Z::throwIf(!$primaryKey = $this->getPrimaryKey(), 500, 'nable to get the primary key can not complete the paging, please set ->orderBy(primaryKey,ASC).');
						$orderBy = "\n" . ' ORDER BY ' . $primaryKey . ' ASC';
						$originVal = $this->_values;
						if ($primaryKey) {
							$orderBy = $orderBy . ' OFFSET ' . $limit . ' ROWS FETCH NEXT ' . $offset . '  ROWS ONLY ';
						}
						$this->_values = $originVal;
					} else {
						$select = ' TOP ' . $offset . ' ' . $select;
					}
				}
			} else {
			}
			$limit = '';
		}
		$sql = "\n" . ' SELECT '
			. $select
			. "\n" . ' FROM ' . $from
			. $where
			. $groupBy
			. $having
			. $orderBy
			. $limit;
		return $sql;
	}
	private function _getFrom() {
		$table = ' ' . call_user_func_array([$this, '_compileFrom'], $this->arFrom) . ' ';
		foreach ($this->arJoin as $join) {
			$table .= call_user_func_array([$this, '_compileJoin'], $join);
		}
		return $table;
	}
	private function _getWhere() {
		$where = '';
		$hasEmptyIn = false;
		foreach ($this->arWhere as $w) {
			if (is_array($w[0])) {
				foreach ($w[0] as $k => &$v) {
					if (is_array($v) && empty($v)) {
						$hasEmptyIn = true;
						break;
					} elseif (is_callable($v)) {
						$_db = $this->cloneDb();
						$v($_db);
						$v = [' (' . $_db->getSql() . ') ', $_db->getSqlValues()];
						$w[5] = true;
					}
				}
				if ($hasEmptyIn) {
					break;
				}
			}
			$where .= call_user_func_array([$this, '_compileWhere'], $w);
		}
		if ($hasEmptyIn) {
			return ' WHERE 0 = 1 ';
		}
		$where = trim($where);
		if ($where) {
			$where = "\n" . ' WHERE ' . $where;
		}
		return $where;
	}
	private function _compileGroupBy() {
		$groupBy = [];
		foreach ($this->arGroupby as $key) {
			$_key = explode('.', $key);
			if (2 == count($_key)) {
				$groupBy[] = $this->_protectIdentifier($this->_checkPrefix($_key[0])) . '.' . $this->_protectIdentifier($_key[1]);
			} else {
				$groupBy[] = $this->_protectIdentifier($_key[0]);
			}
		}
		return join(' , ', $groupBy);
	}
	private function _protectIdentifier($str, $wrap = true) {
		if (!$wrap || stripos($str, '(') || stripos($str, ')') || '*' == trim($str)) {
			return $str;
		}
		$_str = explode(' ', $str);
		$point = (!$this->_isSqlsrv()) ? '``' : '[]';
		$point[3] = ('[' === $point[0]) ? '[dbo].[' : $point[0];
		if (3 == count($_str) && 'as' == strtolower($_str[1])) {
			return $point[3] . $_str[0] . $point[1] . ' AS ' . $point[0] . $_str[2] . $point[1];
		} else {
			return $point[3] . $str . $point[1];
		}
	}
	private function _checkPrefix($str) {
		$prefix = $this->getTablePrefix();
		if ($prefix && false === strpos($str, $prefix)) {
			if (!Z::arrayKeyExists($str, $this->_asTable)) {
				return $prefix . $str;
			}
		}
		return $str;
	}
	private function _compileOrderBy() {
		$orderby = [];
		foreach ($this->arOrderby as $key => $type) {
			if (!$type = strtoupper($type)) {
				$key = strtoupper($key);
				if ($this->_isSqlsrv() && $key === 'RAND()') {
					$key = 'NEWID()';
				}
			}
			$_key = explode('.', $key);
			if (2 == count($_key)) {
				$orderby[] = $this->_protectIdentifier($this->_checkPrefix($_key[0])) . '.' . $this->_protectIdentifier($_key[1]) . ' ' . $type;
			} else {
				$orderby[] = $this->_protectIdentifier($_key[0]) . ' ' . $type;
			}
		}
		return join(' , ', $orderby);
	}
	private function _getLimit() {
		$limit = $this->arLimit;
		if ($limit && !$this->_isSqlsrv()) {
			$limit = "\n" . ' LIMIT ' . $limit;
		}
		return $limit;
	}
	private function _compileSelect() {
		$selects = $this->arSelect;
		if (empty($selects)) {
			$selects[] = ['*', true];
		}
		foreach ($selects as $key => $_value) {
			$protect = $_value[1];
			$value = trim($_value[0]);
			if ('*' != $value) {
				$_info = explode('.', $value);
				if (2 == count($_info)) {
					// $_v       = $this->_checkPrefix($_info[0]);
					$_info[0] = $protect ? $this->_protectIdentifier($_info[0]) : $_info[0];
					$_info[1] = $protect ? $this->_protectIdentifier($_info[1]) : $_info[1];
					$value = join('.', $_info);
				} else {
					$value = $protect ? $this->_protectIdentifier($value) : $value;
				}
			}
			$selects[$key] = $value;
		}
		return join(',', $selects);
	}
	public function getPrimaryKey() {
		if (!$this->primaryKey) {
			$primaryKey = '';
			$db = clone $this;
			if ($this->_isSqlsrv()) {
				$primaryKey = $db->execute('EXEC sp_pkeys @table_name=\'' . trim(strtr(Z::arrayGet($this->arFrom, 0, $this->_getFrom()), ['[' => '', ']' => ''])) . '\'')->value('COLUMN_NAME');
			} elseif ($this->_isMysql()) {
				$sql = 'SHOW FULL COLUMNS FROM ' . trim(strtr($this->_getFrom(), ['`' => '']));
				$result = $db->execute($sql)->rows();
				foreach ($result as $val) {
					if ('pri' == strtolower($val['Key'])) {
						$primaryKey = $val['Field'];
						break;
					}
				}
			}
			$this->primaryKey = $primaryKey;
		}
		return $this->primaryKey;
	}
	public function setPrimaryKey($primaryKey) {
		return $this->primaryKey = $primaryKey;
	}
	private function _getUpdateSql() {
		return join(' ', ["\n" . 'UPDATE ', $this->_getFrom(), "\n" . 'SET', $this->_compileSet(), $this->_getWhere(), $this->_getLimit()]);
	}
	private function _compileSet() {
		$set = [];
		foreach ($this->arSet as $key => $value) {
			list($value, $wrap) = $value;
			if ($wrap) {
				$set[] = $this->_protectIdentifier($key) . ' = ' . '?';
				$this->_values[] = $value;
			} else {
				$set[] = $this->_protectIdentifier($key) . ' = ' . $value;
			}
		}
		return join(' , ', $set);
	}
	private function _getUpdateBatchSql() {
		$sql[] = "\n" . 'UPDATE ';
		$sql[] = $this->_getFrom();
		$sql[] = "\n" . 'SET';
		$sql[] = $this->_compileUpdateBatch();
		$sql[] = $this->_getWhere();
		return join(' ', $sql);
	}
	private function _compileUpdateBatch() {
		list($values, $index) = $this->arUpdateBatch;
		if (count($values) && Z::arrayKeyExists("0.$index", $values)) {
			$ids = [];
			$final = [];
			$_values = [];
			foreach ($values as $key => $val) {
				$ids[] = $val[$index];
				foreach (array_keys($val) as $field) {
					if ($field != $index) {
						if (is_array($val[$field])) {
							$_column = explode(' ', key($val[$field]));
							$column = $this->_protectIdentifier($_column[0]);
							$op = isset($_column[1]) ? $_column[1] : '';
							$final[$field][] = 'WHEN ' . $this->_protectIdentifier($index) . ' = ' . $val[$index] . ' THEN ' . $column . ' ' . $op . ' ' . '?';
							$_values[$field][] = current($val[$field]);
						} else {
							$final[$field][] = 'WHEN ' . $this->_protectIdentifier($index) . ' = ' . $val[$index] . ' THEN ' . '?';
							$_values[$field][] = $val[$field];
						}
					}
				}
			}
			foreach ($_values as $field => $value) {
				if ($field == $index) {
					continue;
				}
				if (!empty($_values[$field]) && is_array($_values[$field])) {
					foreach ($value as $v) {
						$this->_values[] = $v;
					}
				}
			}
			$_values = null;
			$sql = '';
			$cases = '';
			foreach ($final as $k => $v) {
				$cases .= $this->_protectIdentifier($k) . ' = CASE ' . "\n";
				foreach ($v as $row) {
					$cases .= $row . "\n";
				}
				$cases .= 'ELSE ' . $this->_protectIdentifier($k) . ' END, ';
			}
			$sql .= substr($cases, 0, -2);
			return $sql;
		}
		return '';
	}
	private function _getInsertSql() {
		$sql[] = "\n" . 'INSERT INTO ';
		$sql[] = $this->_getFrom();
		$sql[] = $this->_compileInsert();
		return join(' ', $sql);
	}
	private function _compileInsert() {
		$keys = [];
		$values = [];
		foreach ($this->arInsert as $key => $value) {
			$keys[] = $this->_protectIdentifier($key);
			$values[] = '?';
			$this->_values[] = $value;
		}
		if (!empty($keys)) {
			return '(' . join(',', $keys) . ') ' . "\n" . 'VALUES (' . join(',', $values) . ')';
		}
		return '';
	}
	/**
	 * @return string
	 */
	private function _getInsertBatchSql() {
		$sql[] = "\nINSERT INTO ";
		$sql[] = $this->_getFrom();
		$sql[] = $this->_compileInsertBatch();
		return join(' ', $sql);
	}
	private function _compileInsertBatch() {
		$keys = [];
		$values = [];
		if (!empty($this->arInsertBatch[0])) {
			foreach ($this->arInsertBatch[0] as $key => $value) {
				$keys[] = $this->_protectIdentifier($key);
			}
			foreach ($this->arInsertBatch as $row) {
				$_values = [];
				foreach ($row as $key => $value) {
					$_values[] = '?';
					$this->_values[] = $value;
				}
				$values[] = '(' . join(',', $_values) . ')';
			}
			return '(' . join(',', $keys) . ') ' . "\n VALUES " . join(' , ', $values);
		}
		return '';
	}
	private function _getReplaceSql() {
		$sql[] = "\nREPLACE INTO ";
		$sql[] = $this->_getFrom();
		$sql[] = $this->_compileInsert();
		return join(' ', $sql);
	}
	private function _getReplaceBatchSql() {
		$sql[] = "\nREPLACE INTO ";
		$sql[] = $this->_getFrom();
		$sql[] = $this->_compileInsertBatch();
		return join(' ', $sql);
	}
	private function _getDeleteSql() {
		$sql[] = "\nDELETE FROM ";
		$sql[] = $this->_getFrom();
		$sql[] = $this->_getWhere();
		return join(' ', $sql);
	}
	/**
	 * 替换数据
	 *
	 * @param       $table
	 * @param array $data
	 *
	 * @return $this
	 */
	public function replace($table, array $data) {
		$this->_sqlType = 'replace';
		$this->arInsert = $data;
		$this->from($table);
		return $this;
	}
	/**
	 * 批量插入
	 *
	 * @param       $table
	 * @param array $data
	 *
	 * @return $this
	 */
	public function insertBatch($table, array $data) {
		$this->_sqlType = 'insertBatch';
		$this->arInsertBatch = $data;
		$this->_lastInsertBatchCount = count($data);
		$this->from($table);
		return $this;
	}
	/**
	 * 批量替换
	 *
	 * @param       $table
	 * @param array $data
	 *
	 * @return $this
	 */
	public function replaceBatch($table, array $data) {
		$this->_sqlType = 'replaceBatch';
		$this->arInsertBatch = $data;
		$this->_lastInsertBatchCount = count($data);
		$this->from($table);
		return $this;
	}
	/**
	 * 删除数据
	 *
	 * @param       $table
	 * @param array $where
	 *
	 * @return $this
	 */
	public function delete($table, array $where = []) {
		$this->from($table);
		$this->where($where);
		$this->_sqlType = 'delete';
		return $this;
	}
	public function getTable() {
		return Z::arrayGet($this->arFrom, 0, '');
	}
	public function getWhere() {
		return Z::arrayMap($this->arWhere, function ($v) {
			list($where, $leftWrap, $rightWrap) = $v;
			return [$where, $leftWrap, $rightWrap];
		});
	}
	/**
	 * 查询条件
	 *
	 * @param        $where
	 * @param string $leftWrap
	 * @param string $rightWrap
	 *
	 * @return $this
	 */
	public function where($where, $leftWrap = 'AND', $rightWrap = '') {
		if (!empty($where)) {
			//&& is_array($where)
			$this->arWhere[] = [$where, $leftWrap, $rightWrap, count($this->arWhere)];
		}
		return $this;
	}
	/**
	 * 更新数据
	 *
	 * @param       $table
	 * @param array $data
	 * @param array $where
	 *
	 * @return $this
	 */
	public function update($table, array $data = [], array $where = []) {
		$this->from($table);
		$this->where($where);
		foreach ($data as $key => $value) {
			if (is_bool($value)) {
				$this->set($key, ((false === $value) ? 0 : 1), true);
			} elseif (is_null($value)) {
				$this->set($key, 'NULL', false);
			} else {
				$this->set($key, $value, true);
			}
		}
		return $this;
	}
	/**
	 * 设置数据
	 *
	 * @param      $key
	 * @param      $value
	 * @param bool $wrap
	 *
	 * @return $this
	 */
	public function set($key, $value, $wrap = true) {
		$this->_sqlType = 'update';
		$this->arSet[$key] = [$value, $wrap];
		return $this;
	}
	/**
	 * 批量更新
	 *
	 * @param string $table  表名
	 * @param array  $values 必须包含$index字段
	 * @param string $index  唯一字段名称，一般是主键id
	 *
	 * @return object|int
	 */
	public function updateBatch($table, array $values, $index) {
		$this->from($table);
		$this->_sqlType = 'updateBatch';
		$this->arUpdateBatch = [$values, $index];
		if (!$values) {
			$ids = [];
			foreach ($values as $val) {
				$ids[] = $val[$index];
			}
			$this->where([$index => $ids]);
		}
		return $this;
	}
	/**
	 * 加表前缀，保护字段名和表名
	 *
	 * @param string $str 比如：user.id , id
	 *
	 * @return string
	 */
	public function wrap($str) {
		$_key = explode('.', $str);
		if (2 == count($_key)) {
			return $this->_protectIdentifier($this->_checkPrefix($_key[0])) . '.' . $this->_protectIdentifier($_key[1]);
		} else {
			return $this->_protectIdentifier($_key[0]);
		}
	}
	public function __toString() {
		return $this->getSql();
	}
	protected function _getValues() {
		return $this->_values;
	}
	private function __clone() {
		$this->_reset();
	}
	private function _compileWhere($where, $leftWrap = 'AND', $rightWrap = '', $index = -1, $child = false) {
		$_where = [];
		if (0 == $index) {
			$str = strtoupper(trim($leftWrap));
			foreach (['AND', 'OR'] as $v) {
				if (false !== stripos($str, $v)) {
					$leftWrap = '';
					break;
				}
			}
		}
		if (is_string($where)) {
			return ' ' . $leftWrap . ' ' . $where . $rightWrap . ' ';
		}
		foreach ($where as $key => $value) {
			$key = trim($key);
			$_key = explode(' ', $key, 2);
			$op = 2 == count($_key) ? strtoupper($_key[1]) : '';
			$key = explode('.', $_key[0]);
			if (2 == count($key)) {
				$key = $this->_protectIdentifier($this->_checkPrefix($key[0])) . '.' . $this->_protectIdentifier($key[1]);
			} else {
				$key = $this->_protectIdentifier(current($key));
			}
			if ($child) {
				$_where[] = $key . ($op ? $op : ' =') . $value[0];
				foreach ($value[1] as $v) {
					array_push($this->_values, $v);
				}
			} elseif (is_array($value) && !$child) {
				if ('BETWEEN' !== $op) {
					$op = $op ? $op . ' IN ' : ' IN ';
					$perch = '(' . join(',', array_fill(0, count($value), '?')) . ')';
				} else {
					$perch = '? AND ?';
					$op = ' BETWEEN ';
				}
				$_where[] = $key . ' ' . $op . $perch;
				foreach ($value as $v) {
					array_push($this->_values, $v);
				}
			} elseif (is_bool($value)) {
				$op = $op ? $op : '=';
				$value = $value ? 1 : 0;
				$_where[] = $key . ' ' . $op . ' ? ';
				array_push($this->_values, $value);
			} elseif (is_null($value)) {
				$op = $op ? $op : 'IS';
				$_where[] = $key . ' ' . $op . ' NULL ';
			} else {
				$op = $op ? $op : '=';
				$_where[] = $key . ' ' . $op . ' ? ';
				array_push($this->_values, $value);
			}
		}
		return ' ' . $leftWrap . ' ' . join(' AND ', $_where) . $rightWrap . ' ';
	}
	private function _compileFrom($from, $as = '', $wrap = true) {
		if ($as) {
			$this->_asTable[$as] = 1;
			$as = ' AS ' . $this->_protectIdentifier($as) . ' ';
		}
		return $this->_protectIdentifier($this->_checkPrefix($from), $wrap) . $as;
	}
	private function _compileJoin($table, $on, $type = '') {
		if (is_array($table)) {
			$this->_asTable[current($table)] = 1;
			$table = $this->_protectIdentifier($this->_checkPrefix(key($table))) . ' AS ' . $this->_protectIdentifier(current($table)) . ' ';
		} else {
			$table = $this->_protectIdentifier($this->_checkPrefix($table));
		}
		list($left, $right) = explode('=', $on);
		$_left = explode('.', $left);
		$_right = explode('.', $right);
		if (2 == count($_left)) {
			$_left[0] = $this->_protectIdentifier($this->_checkPrefix($_left[0]));
			$_left[1] = $this->_protectIdentifier($_left[1]);
			$left = ' ' . join('.', $_left) . ' ';
		} else {
			$left = $this->_protectIdentifier($left);
		}
		if (2 == count($_right)) {
			$_right[0] = $this->_protectIdentifier($this->_checkPrefix($_right[0]));
			$_right[1] = $this->_protectIdentifier($_right[1]);
			$right = ' ' . join('.', $_right) . ' ';
		} else {
			$right = $this->_protectIdentifier($right);
		}
		$on = $left . ' = ' . $right;
		return ' ' . $type . ' JOIN ' . $table . ' ON ' . $on . ' ';
	}
}
class Zls_Database_Resultset {
	private $_resultSet = [];
	private $_rowsKey = '';
	public function __construct($resultSet) {
		$this->_resultSet = $resultSet;
	}
	/**
	 * 读取数据总数
	 * @return int
	 */
	public function total() {
		return count($this->_resultSet);
	}
	/**
	 * bean对象
	 *
	 * @param      $beanClassName
	 * @param null $index
	 *
	 * @return Zls_Bean
	 */
	public function bean($beanClassName, $index = null) {
		$row = $this->row($index);
		$object = Z::bean($beanClassName, $row, false);
		return $object;
	}
	/**
	 * 读取单条数据
	 *
	 * @param null $index
	 * @param bool $isAssoc
	 *
	 * @return array|mixed
	 */
	public function row($index = null, $isAssoc = true) {
		if (!is_null($index) && Z::arrayKeyExists($index, $this->_resultSet)) {
			return $isAssoc ? $this->_resultSet[$index] : array_values($this->_resultSet[$index]);
		} else {
			$row = current($this->_resultSet);
			return $isAssoc ? (is_array($row) ? $row : []) : array_values($row);
		}
	}
	public function beans($beanClassName, $toArray = true) {
		$rowsKey = $this->_rowsKey;
		$this->_rowsKey = '';
		$objects = [];
		$rows = $this->rows();
		foreach ($rows as $row) {
			$object = Z::bean($beanClassName, $row, false);
			if ($toArray) {
				$object = $object->toArray();
			}
			if ($rowsKey) {
				$objects[$row[$rowsKey]] = $object;
			} else {
				$objects[] = $object;
			}
		}
		return $objects;
	}
	/**
	 * 读取多条数据
	 *
	 * @param bool $isAssoc
	 *
	 * @return array
	 */
	public function rows($isAssoc = true) {
		$key = $this->_rowsKey;
		$this->_rowsKey = '';
		if ($key) {
			if ($isAssoc) {
				$rows = [];
				foreach ($this->_resultSet as $row) {
					$rows[$row[$key]] = $row;
				}
				return $rows;
			} else {
				$rows = [];
				foreach ($this->_resultSet as $row) {
					$rows[$row[$key]] = array_values($row);
				}
				return $rows;
			}
		} else {
			if ($isAssoc) {
				return $this->_resultSet;
			} else {
				$rows = [];
				foreach ($this->_resultSet as $row) {
					$rows[] = array_values($row);
				}
				return $rows;
			}
		}
	}
	/**
	 * 读取多条数据值
	 *
	 * @param $columnName
	 *
	 * @return array
	 */
	public function values($columnName) {
		$rowsKey = $this->_rowsKey;
		$this->_rowsKey = '';
		$columns = [];
		foreach ($this->_resultSet as $row) {
			if (Z::arrayKeyExists($columnName, $row)) {
				if ($rowsKey) {
					$columns[$row[$rowsKey]] = $row[$columnName];
				} else {
					$columns[] = $row[$columnName];
				}
			} else {
				return [];
			}
		}
		return $columns;
	}
	/**
	 * 读取数据值
	 *
	 * @param      $columnName
	 * @param null $default
	 * @param null $index
	 *
	 * @return mixed|null
	 */
	public function value($columnName, $default = null, $index = null) {
		$row = $this->row($index);
		return ($columnName && Z::arrayKeyExists($columnName, $row)) ? $row[$columnName] : $default;
	}
	/**
	 * 设置数据数组下标
	 *
	 * @param $columnName
	 *
	 * @return $this
	 */
	public function key($columnName) {
		$this->_rowsKey = $columnName;
		return $this;
	}
}
abstract class Zls_Middleware {
	abstract public function handle($request, callable $next);
	/**
	 * @return array
	 */
	abstract public function classes($request);
}
abstract class Zls_Bean {
	protected static $noTransform = false;
	public function __construct($row = null) {
		$this->init($row ?: []);
	}
	final public function init(array $row) {
		foreach ($row as $key => $value) {
			$method = 'set' . Z::strSnake2Camel($key);
			$this->{$method}($value);
		}
	}
	public function __invoke(...$key) {
		return $this->toArray($key);
	}
	final public function toArray($fields = []) {
		$filter = function ($key) use ($fields) {
			return !$fields || (is_array($fields) && in_array($key, $fields));
		};
		$args = Z::arrayFilter($fields !== false ? get_object_vars($this) : [], function ($v, $k) use ($filter) {
			return $filter($k);
		});
		$methods = array_diff(get_class_methods($this), get_class_methods(__CLASS__));
		foreach ($methods as $method) {
			$key = static::_get($method);
			if (!$key || !$filter($key)) {
				continue;
			}
			$args[$key] = $this->$method();
		}
		return $args;
	}
	private static function _get($method) {
		if (!Z::strBeginsWith($method, 'get')) {
			return null;
		}
		$method = str_replace('get', '', $method);
		return static::$noTransform ? $method : lcfirst(Z::strCamel2Snake(str_replace('get', '', $method)));
	}
	public function __call($method, $args) {
		if (Z::strBeginsWith($method, 'set') && $method = static::_set($method)) {
			return $this->$method = z::arrayGet($args, 0);
		} elseif (Z::strBeginsWith($method, 'get') && $method = static::_get($method)) {
			return $this->$method;
		}
		Z::throwIf(true, 500, 'Call to undefined method ' . get_called_class() . '->' . $method . '()');
		return false;
	}
	private static function _set($method) {
		if (!Z::strBeginsWith($method, 'set')) {
			return null;
		}
		$method = str_replace('set', '', $method);
		return static::$noTransform ? $method : lcfirst(Z::strCamel2Snake($method));
	}
}
abstract class Zls_Database {
	private $driverType;
	private $database;
	private $tablePrefix;
	private $pconnect;
	private $debug;
	private $timeout;
	private $trace;
	private $charset;
	private $collate;
	private $tablePrefixSqlIdentifier;
	private $slowQueryTime;
	private $slowQueryHandle;
	private $slowQueryDebug;
	private $minIndexType;
	private $indexDebug;
	private $indexHandle;
	private $masters;
	private $slaves;
	private $resetSql;
	private $attribute;
	private $connectionMasters;
	private $connectionSlaves;
	private $versionThan56 = false;
	private $_errorMsg;
	private $_lastSql;
	private $_lastPdoInstance;
	private $_isInTransaction = false;
	private $_config;
	private $_lastInsertId = 0;
	private $_traceRes = [];
	private $_cacheTime = null;
	private $_cacheKey;
	private $_masterPdo = null;
	private $_locked = false;
	public function __construct(array $config = []) {
		$this->setConfig($config);
	}
	public function getDefaultConfig() {
		return [
			'debug' => true,
			'driverType' => 'mysql',
			'production' => true,
			'trace' => false,
			'timeout' => 5,
			'pconnect' => false,
			'charset' => 'utf8',
			'collate' => 'utf8_general_ci',
			'database' => '',
			'tablePrefix' => '',
			'tablePrefixSqlIdentifier' => '_prefix_',
			'slowQueryDebug' => false,
			'slowQueryTime' => 3000,
			'slowQueryHandle' => null,
			'indexDebug' => false,
			/*
				             * 索引使用的最小情况，只有小于最小情况的时候才会记录sql到日志
				             * minIndexType值从好到坏依次是:
				             * system > const > eq_ref > ref > fulltext > ref_or_null
				             * > index_merge > unique_subquery > index_subquery > range
				             * > index > ALL一般来说，得保证查询至少达到range级别，最好能达到ref
			*/
			'minIndexType' => 'ALL',
			'indexHandle' => null,
			'attribute' => [],
			'masters' => [
				'master01' => [
					'hostname' => '127.0.0.1',
					'port' => 3306,
					'username' => 'root',
					'password' => '',
				],
			],
			'slaves' => [],
		];
	}
	public function &getLastPdoInstance() {
		return $this->_lastPdoInstance;
	}
	/**
	 * 锁定数据库连接，后面的读写都使用同一个主数据库连接
	 * @return $this
	 */
	public function lock() {
		$this->_locked = true;
		return $this;
	}
	/**
	 * 解锁数据库连接，后面的读写使用不同的数据库连接
	 * @return $this
	 */
	public function unlock() {
		$this->_locked = false;
		return $this;
	}
	/**
	 * 获取上一条数据id（主键）
	 * @return int
	 */
	public function lastId() {
		if ($this->_isSqlite()) {
			return $this->_lastInsertBatchCount > 1 ? ($this->_lastInsertId - $this->_lastInsertBatchCount + 1) : $this->_lastInsertId;
		} else {
			return $this->_lastInsertId;
		}
	}
	public function _isSqlite() {
		return $this->_driverTypeIsString() && 'sqlite' == strtolower($this->getDriverType());
	}
	public function _driverTypeIsString() {
		return 'string' == gettype($this->getDriverType());
	}
	public function getDriverType() {
		return $this->driverType;
	}
	public function setDriverType($driverType) {
		$this->driverType = $driverType;
		return $this;
	}
	public function resetSql() {
		return $this->resetSql;
	}
	public function error() {
		return $this->_errorMsg;
	}
	public function lastSql() {
		return $this->_lastSql;
	}
	public function getSlowQueryDebug() {
		return $this->slowQueryDebug;
	}
	public function setSlowQueryDebug($slowQueryDebug) {
		$this->slowQueryDebug = $slowQueryDebug;
		return $this;
	}
	public function getIndexDebug() {
		return $this->indexDebug;
	}
	public function setIndexDebug($indexDebug) {
		$this->indexDebug = $indexDebug;
		return $this;
	}
	public function &getSlowQueryHandle() {
		return $this->slowQueryHandle;
	}
	public function setSlowQueryHandle(Zls_Database_SlowQuery_Handle $slowQueryHandle) {
		$this->slowQueryHandle = $slowQueryHandle;
		return $this;
	}
	public function &getIndexHandle() {
		return $this->indexHandle;
	}
	public function setIndexHandle(Zls_Database_Index_Handle $indexHandle) {
		$this->indexHandle = $indexHandle;
		return $this;
	}
	public function getConfig() {
		return $this->_config;
	}
	public function setConfig(array $config = []) {
		foreach (($this->_config = array_merge($this->getDefaultConfig(), $config)) as $key => $value) {
			$this->{$key} = $value;
		}
		$this->connectionMasters = [];
		$this->connectionSlaves = [];
		$this->_errorMsg = '';
		$this->_lastSql = '';
		$this->_isInTransaction = false;
		$this->_lastInsertId = 0;
		$this->_lastPdoInstance = null;
		$this->_cacheKey = '';
		$this->_cacheTime = null;
		$this->_masterPdo = '';
		$this->_locked = false;
	}
	public function getMasters() {
		return $this->masters;
	}
	public function setMasters($masters) {
		$this->masters = $masters;
		return $this;
	}
	public function getMaster($key) {
		return $this->masters[$key];
	}
	public function getSlaves() {
		return $this->slaves;
	}
	public function setSlaves($slaves) {
		$this->slaves = $slaves;
		return $this;
	}
	public function getSlave($key) {
		return $this->slaves[$key];
	}
	/**
	 * @return bool
	 */
	public function begin() {
		if (!$this->_init()) {
			return false;
		}
		$this->_masterPdo->beginTransaction();
		$this->_isInTransaction = true;
		return true;
	}
	private function _init() {
		$info = [
			'master' => [
				'getMasters',
				'connectionMasters',
			],
			'slave' => [
				'getSlaves',
				'connectionSlaves',
			],
		];
		try {
			foreach ($info as $type => $group) {
				$configGroup = $this->{$group[0]}();
				$connections = &$this->{$group[1]};
				foreach ($configGroup as $key => $config) {
					//  如果连接已经存在则不再初始化新的
					if (!Z::arrayKeyExists($key, $connections)) {
						if (true) {
						}
						if ($this->_driverTypeIsString()) {
							$options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
							$options[PDO::ATTR_PERSISTENT] = $this->getPconnect();
							$options[PDO::ATTR_STRINGIFY_FETCHES] = false;
							$options[PDO::ATTR_EMULATE_PREPARES] = false;
							$options[PDO::ATTR_ORACLE_NULLS] = PDO::NULL_TO_STRING;
							if ($this->_isMysql()) {
								$options[PDO::ATTR_TIMEOUT] = $this->getTimeout();
								$options[\PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES ' . $this->getCharset() . ' COLLATE ' . $this->getCollate();
								$dsn = 'mysql:host=' . $config['hostname'] . ';port=' . $config['port'] . ';dbname=' . $this->getDatabase() . ';charset=' . $this->getCharset();
								$connections[$key] = new \Zls_PDO($dsn, $config['username'], $config['password'], $options);
								$connections[$key]->exec('SET NAMES ' . $this->getCharset());
							} elseif ($this->_isSqlsrv()) {
								$dsn = 'sqlsrv:Server=' . $config['hostname'] . ',' . $config['port'] . ';Database=' . $this->getDatabase() . ';MultipleActiveResultSets=false';
								unset($options[PDO::ATTR_PERSISTENT], $options[PDO::ATTR_EMULATE_PREPARES]);
								$options = $options + [1001 => $this->getTimeout()];
								if (property_exists('PDO', 'SQLSRV_ATTR_FETCHES_NUMERIC_TYPE')) {
									$options[PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE] = true;
								}
								$connections[$key] = new \Zls_PDO($dsn, $config['username'], $config['password'], $options);
								$connections[$key]->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
							} elseif ($this->_isSqlite()) {
								Z::throwIf(!file_exists($this->getDatabase()), 'Database', 'sqlite3 database file [' . Z::realPath($this->getDatabase()) . '] not found', 'ERROR');
								$connections[$key] = new \Zls_PDO('sqlite:' . $this->getDatabase(), null, null, $options);
							} else {
								Z::throwIf(true, 'Database', 'unknown driverType [ ' . $this->getDriverType() . ' ]', 'ERROR');
							}
						} else {
							$db = $this->getDriverType();
							$connections[$key] = is_callable($db) ? $db() : $db;
						}
						$getAttribute = $this->getAttribute();
						if (!empty($getAttribute) && is_array($getAttribute)) {
							foreach ($getAttribute as $k => $v) {
								$connections[$key]->setAttribute($k, $v);
							}
						}
					}
				}
			}
			if (empty($this->connectionSlaves) && !empty($this->connectionMasters)) {
				$this->connectionSlaves[0] = $this->connectionMasters[array_rand($this->connectionMasters)];
			}
			if (empty($this->_masterPdo) && !empty($this->connectionMasters)) {
				$this->_masterPdo = $this->connectionMasters[array_rand($this->connectionMasters)];
			}
			return !(empty($this->connectionMasters) && empty($this->connectionSlaves));
		} catch (\Exception $e) {
			$this->_displayError($e);
		}
		return false;
	}
	public function getPconnect() {
		return $this->pconnect;
	}
	public function setPconnect($pconnect) {
		$this->pconnect = $pconnect;
		return $this;
	}
	public function _isMysql() {
		return $this->_driverTypeIsString() && 'mysql' == strtolower($this->getDriverType());
	}
	/**
	 * @return mixed
	 */
	public function getTimeout() {
		return $this->timeout;
	}
	/**
	 * @param mixed $timeout
	 */
	public function setTimeout($timeout) {
		$this->timeout = $timeout;
	}
	/**
	 * @return string
	 */
	public function getCharset() {
		return $this->charset;
	}
	public function setCharset($charset) {
		$this->charset = $charset;
		return $this;
	}
	/**
	 * @return string
	 */
	public function getCollate() {
		return $this->collate;
	}
	public function setCollate($collate) {
		$this->collate = $collate;
		return $this;
	}
	/**
	 * @return string
	 */
	public function getDatabase() {
		return $this->database;
	}
	public function setDatabase($database) {
		$this->database = $database;
		return $this;
	}
	public function _isSqlsrv() {
		return $this->_driverTypeIsString() && 'sqlsrv' == strtolower($this->getDriverType());
	}
	public function getAttribute() {
		return $this->attribute;
	}
	protected function _displayError($e, $code = 0) {
		$sql = $this->_lastSql ? ' , ' . "\n" . 'with query : ' . $this->_lastSql : '';
		$group = 'Database Group : [ ' . $this->group . ' ] , error : ';
		if ($e instanceof Exception) {
			$this->_errorMsg = $e->getMessage() . $sql;
		} else {
			$this->_errorMsg = $e . $sql;
		}
		if ($this->getDebug() || $this->_isInTransaction) {
			if ($e instanceof Exception) {
				throw new \Zls_Exception_Database(
					Z::toUtf8($group . $this->_errorMsg),
					500,
					'Zls_Exception_Database',
					$e->getFile(),
					$e->getLine()
				);
			} else {
				throw new \Zls_Exception_Database(Z::toUtf8($group . $e . $sql), $code);
			}
		}
	}
	public function getDebug() {
		return $this->debug;
	}
	public function setDebug($debug) {
		$this->debug = $debug;
		return $this;
	}
	/**
	 * @return Zls_PDO
	 */
	public function pdoInstance() {
		if (!$this->_masterPdo) {
			$this->_init();
		}
		return $this->_masterPdo;
	}
	public function commit() {
		if (!$this->_init()) {
			return false;
		}
		$this->_masterPdo->commit();
		$this->_isInTransaction = $this->_masterPdo->isInTransaction();
	}
	public function rollback() {
		if (!$this->_init()) {
			return false;
		}
		$this->_masterPdo->rollback();
	}
	public function cache($cacheTime, $cacheKey = '') {
		$this->_cacheTime = (int) $cacheTime;
		$this->_cacheKey = $cacheKey;
		return $this;
	}
	private function vsprintfSql($sql, $values) {
		return !!$values ? vsprintf(str_replace(['%', '?'], ['%%', '%s'], $sql), z::arrayMap($values, function ($e) {
			return is_string($e) ? "'{$e}'" : $e;
		})) : $sql;
	}
	/**
	 * @return string
	 */
	public function reset() {
		if ($this->arFrom) {
			$sql = $this->getSql();
			$values = $this->getSqlValues();
			if ($resetSql = $this->resetSql()) {
				$resetSql($sql, $values, $this->vsprintfSql($sql, $values));
			}
			$preview = $this->vsprintfSql($sql, $values);
		} else {
			$preview = '';
		}
		return Z::tap($preview, function () {
			$this->_cacheKey = '';
			$this->_cacheTime = null;
			$this->_reset();
		});
	}
	abstract public function getSql();
	public function getSqlValues() {
		return $this->_getValues();
	}
	abstract protected function _getValues();
	/**
	 * 执行一个sql语句，写入型的返回bool或者影响的行数（insert,delete,replace,update），搜索型的返回结果集
	 *
	 * @param string $sql    sql语句
	 * @param array  $values 参数
	 * @param bool   $reconnection
	 *
	 * @return array|bool|int|Zls_Database_Resultset
	 */
	public function execute($sql = '', array $values = [], $reconnection = true) {
		$cfg = Zls::getConfig();
		$middleware = $cfg->getDatabaseMiddleware();
		$pip = new Zls_Pipeline();
		$trace = [];
		if ($this->slowQueryDebug || $this->indexDebug) {
			$trace = Z::tap(debug_backtrace(), function (&$trace) {
				$_trace = ('Zls_Dao' == Z::arrayGet($trace, '1.class')) ? $trace[1] : $trace[0];
				$trace = [
					'file' => $_trace['file'],
					'line' => $_trace['line'],
					'class' => $_trace['class'],
					'function' => $_trace['function'],
				];
			});
		}
		if (!$this->_init()) {
			return false;
		}
		$startTime = Z::microtime();
		$sql = $sql ? $this->_checkPrefixIdentifier($sql) : $this->getSql();
		$values = !empty($values) ? $values : $this->_getValues();
		$resetSql = $this->resetSql();
		if (is_callable($resetSql)) {
			$middleware[] = function ($request, callable $next) use ($resetSql) {
				list($sql, $values, $pdo) = $request;
				$resetSql($sql, $values, $this->vsprintfSql($sql, $values));
				return $next([$sql, $values, $pdo]);
			};
		}
		$pipRun = function (&$pdo) use ($pip, $middleware, &$sql, &$values) {
			$pip->send([$sql, $values, $pdo])->then($middleware, function ($request) use (&$sql, &$values, &$pdo) {
				list($sql, $values, $pdo) = $request;
			});
			$this->_lastSql = $this->vsprintfSql($sql, $values);
			$this->_lastPdoInstance = &$pdo;
		};
		$cacheHandle = null;
		if (is_numeric($this->_cacheTime)) {
			$cacheHandle = Z::config()->getCacheHandle();
			Z::throwIf(empty($cacheHandle), 500, 'no cache handle found , please set cache handle', 'ERROR');
			$key = empty($this->_cacheKey) ? md5($sql . var_export($values, true)) : $this->_cacheKey;
			if ($this->_cacheTime > 0) {
				$return = $cacheHandle->get($key);
				if (!is_null($return)) {
					$this->_cacheKey = '';
					$this->_cacheTime = null;
					$this->_reset();
					return $return;
				}
			} else {
				$cacheHandle->delete($key);
			}
		}
		$isWriteType = $this->_isWriteType($sql);
		$isWritetRowsType = $this->_isWriteRowsType($sql);
		$isWriteInsertType = $this->_isWriteInsertType($sql);
		$return = false;
		try {
			$pdo = &$this->_masterPdo;
			if ($this->_isInTransaction) {
				$pipRun($pdo);
				if ($sth = $pdo->prepare($sql)) {
					if ($isWriteType) {
						$status = $sth->execute($values);
						$return = $isWritetRowsType ? $sth->rowCount() : $status;
						$this->_lastInsertId = $isWriteInsertType ? $pdo->lastInsertId() : 0;
					} else {
						$return = $sth->execute($values) ? $sth->fetchAll(PDO::FETCH_ASSOC) : [];
						$return = new \Zls_Database_Resultset($return);
					}
				} else {
					$errorInfo = $pdo->errorInfo();
					$this->_displayError($errorInfo[2], $errorInfo[1]);
				}
			} else {
				if (!$this->isLocked()) {
					if ($isWriteType) {
						$pdo = &$this->connectionMasters[array_rand($this->connectionMasters)];
					} else {
						$pdo = &$this->connectionSlaves[array_rand($this->connectionSlaves)];
					}
				}
				$pipRun($pdo);
				if ($sth = @$pdo->prepare($sql)) {
					if ($isWriteType) {
						$status = $sth->execute($values);
						$return = $isWritetRowsType ? $sth->rowCount() : $status;
						$this->_lastInsertId = $isWriteInsertType ? $pdo->lastInsertId() : 0;
					} else {
						$return = $sth->execute($values) ? $sth->fetchAll(PDO::FETCH_ASSOC) : [];
						$return = new \Zls_Database_Resultset($return);
					}
				} else {
					$errorInfo = $pdo->errorInfo();
					$this->_displayError($errorInfo[2], $errorInfo[1]);
				}
			}
			$usingTime = (Z::microtime() - $startTime) . '';
			$explainRows = [];
			if ($this->_isMysql() && ($this->slowQueryDebug || $this->indexDebug) && (($this->_isExplain56Type($sql) && $this->versionThan56) || ($this->_isExplainType($sql) && !$this->versionThan56))) {
				reset($this->connectionMasters);
				$sth = $this->connectionMasters[key($this->connectionMasters)]->prepare('EXPLAIN ' . $sql);
				$sth->execute($values);
				$explainRows = $sth->fetchAll(PDO::FETCH_ASSOC);
			}
			if ($this->slowQueryDebug && ($usingTime >= $this->getSlowQueryTime())) {
				if ($this->slowQueryHandle instanceof Zls_Database_SlowQuery_Handle) {
					$this->slowQueryHandle->handle($sql, var_export($values, true), var_export($explainRows, true), $usingTime, $trace);
				}
			}
			if ($this->indexDebug && $this->indexHandle instanceof Zls_Database_Index_Handle) {
				$badIndex = false;
				if ($this->_isMysql()) {
					$order = [
						'system' => 1,
						'const' => 2,
						'eq_ref' => 3,
						'ref' => 4,
						'fulltext' => 5,
						'ref_or_null' => 6,
						'index_merge' => 7,
						'unique_subquery' => 8,
						'index_subquery' => 9,
						'range' => 10,
						'index' => 11,
						'all' => 12,
					];
					foreach ($explainRows as $row) {
						if (
							Z::arrayKeyExists(
								strtolower($row['type']),
								$order
							)
							&& Z::arrayKeyExists(strtolower($this->getMinIndexType()), $order)
						) {
							$key = $order[strtolower($row['type'])];
							$minKey = $order[strtolower($this->getMinIndexType())];
							if ($key > $minKey) {
								if (false === stripos($row['Extra'], 'optimized')) {
									$badIndex = true;
									break;
								}
							}
						}
					}
				}
				if ($badIndex) {
					$this->indexHandle->handle($sql, var_export($values, true), var_export($explainRows, true), $usingTime, $trace);
				}
			}
			if (!is_null($this->_cacheTime) && (bool) $return->row()) {
				$key = empty($this->_cacheKey) ? md5($sql . var_export($values, true)) : $this->_cacheKey;
				if ($this->_cacheTime > 0) {
					$cacheHandle->set($key, $return, $this->_cacheTime);
				} else {
					$cacheHandle->delete($key);
				}
			}
			$this->_cacheKey = '';
			$this->_cacheTime = null;
			$this->_reset();
		} catch (\Exception $e) {
			if ($reconnection && stristr($e->getMessage(), 'server has gone away')) {
				$this->close();
				return $this->execute($sql, $values, false);
			} else {
				$this->_reset();
				$this->_displayError($e);
			}
		}
		if ($this->_isMysql() && $this->getTrace() && true == Z::config()->getTraceStatus('mysql')) {
			if (preg_match('/SELECT /ims', $sql)) {
				try {
					$trace['runtime'] = (Z::microtime() - $startTime) . 'ms';
					$trace['time'] = date('Y-m-d H:i:s');
					$sth = @$pdo->prepare('EXPLAIN ' . $sql);
					$sql = str_replace("\n", ' ', $sql);
					$arr = $sth->execute($values) ? $sth->fetch(PDO::FETCH_ASSOC) : [];
					$this->_traceRes = $trace + $arr + ['Values' => join(',', $values), 'SQL' => $sql];
					$this->trace();
				} catch (\Exception $e) {
				}
			}
		}
		return $return;
	}
	private function _checkPrefixIdentifier($str) {
		$prefix = $this->getTablePrefix();
		$identifier = $this->getTablePrefixSqlIdentifier();
		return $identifier ? str_replace($identifier, $prefix, $str) : $str;
	}
	public function getTablePrefix() {
		return $this->tablePrefix;
	}
	public function setTablePrefix($tablePrefix) {
		$this->tablePrefix = $tablePrefix;
		return $this;
	}
	public function getTablePrefixSqlIdentifier() {
		return $this->tablePrefixSqlIdentifier;
	}
	public function setTablePrefixSqlIdentifier($tablePrefixSqlIdentifier) {
		$this->tablePrefixSqlIdentifier = $tablePrefixSqlIdentifier;
		return $this;
	}
	private function _isWriteType($sql) {
		if (!preg_match(
			'/^\s*"?(SET|INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|TRUNCATE|LOAD DATA|COPY|ALTER|GRANT|REVOKE|LOCK|UNLOCK)\s+/i',
			$sql
		)) {
			return false;
		}
		return true;
	}
	private function _isWriteRowsType($sql) {
		if (!preg_match('/^\s*"?(INSERT|UPDATE|DELETE|REPLACE)\s+/i', $sql)) {
			return false;
		}
		return true;
	}
	private function _isWriteInsertType($sql) {
		if (!preg_match('/^\s*"?(INSERT|REPLACE)\s+/i', $sql)) {
			return false;
		}
		return true;
	}
	/**
	 * 数据库连接是否处于锁定状态
	 * @return bool
	 */
	public function isLocked() {
		return $this->_locked;
	}
	private function _isExplain56Type($sql) {
		if (!preg_match('/^\s*"?(SELECT|INSERT|UPDATE|DELETE|REPLACE)\s+/i', $sql)) {
			return false;
		}
		return true;
	}
	private function _isExplainType($sql) {
		if (!preg_match('/^\s*"?(SELECT)\s+/i', $sql)) {
			return false;
		}
		return true;
	}
	public function getSlowQueryTime() {
		return $this->slowQueryTime;
	}
	public function setSlowQueryTime($slowQueryTime) {
		$this->slowQueryTime = $slowQueryTime;
		return $this;
	}
	public function getMinIndexType() {
		return $this->minIndexType;
	}
	public function setMinIndexType($minIndexType) {
		$this->minIndexType = $minIndexType;
		return $this;
	}
	public function close() {
		$this->_masterPdo = null;
		$this->_lastPdoInstance = null;
		$this->connectionMasters = [];
		$this->connectionSlaves = [];
		return $this;
	}
	public function getTrace() {
		return $this->trace;
	}
	public function setTrace($trace) {
		$this->trace = $trace;
		return $this;
	}
	public function trace() {
		if ((bool) $this->_traceRes) {
			Z::log(null, false)->mysql($this->_traceRes, $this->getDriverType());
		}
	}
}
/**
 * Class Zls_Controller.
 * @method void before($method, $controller, $args, $methodFull, $class)
 * @method string after($contents, $method, $controller, $args, $methodFull, $class)
 * @method string call($method, $controller, $args, $methodFull, $class)
 */
abstract class Zls_Controller {
}
abstract class Zls_Model {
}
abstract class Zls_Business {
}
abstract class Zls_Task {
	protected $debug = false;
	protected $debugError = false;
	/**
	 * Zls_Task constructor
	 */
	public function __construct() {
		Z::throwIf(!Z::isCli(), 500, 'Task only in cli mode', 'ERROR');
		$args = Z::getOpt();
		$this->debug = Z::arrayGet($args, 'debug');
		$this->debugError = Z::arrayGet($args, 'debug-error');
	}
	public function _filePutContents($lockFilePath, $content) {
		Z::throwIf(false === Z::forceUmask(function () use ($lockFilePath, $content) {
			return file_put_contents($lockFilePath, $content);
		}), 500, 'can not create file : [ ' . $lockFilePath . ' ]', 'ERROR');
	}
	public function _execute($args) {
		$startTime = Z::microtime();
		$class = get_class($this);
		if ($this->debugError) {
			$_startTime = date('Y-m-d H:i:s.') . substr($startTime . '', strlen($startTime . '') - 3);
			$error = $this->execute($args);
			if ($error) {
				$this->_log('Task [ ' . $class . ' ] execute failed , started at [ ' . $_startTime . ' ], use time ' . (Z::microtime() - $startTime) . ' ms , exited with error : [ ' . $error . ' ]');
			}
		} else {
			$this->_log('Task [ ' . $class . ' ] start');
			$this->execute($args);
			$this->_log('Task [ ' . $class . ' ] end , use time ' . (Z::microtime() - $startTime) . ' ms');
		}
	}
	abstract public function execute($args);
	public function _log($msg, $time = true) {
		if ($this->debug || $this->debugError) {
			$nowTime = '' . Z::microtime();
			echo ($time ? date('[Y-m-d H:i:s.' . substr($nowTime, strlen($nowTime) - 3) . ']') . ' [PID:' . sprintf('%- 5d', getmypid()) . '] ' : '') . $msg . "\n";
		}
	}
	/**
	 * @param $pid
	 *
	 * @return bool|false|int
	 */
	final public function pidIsExists($pid) {
		if (PATH_SEPARATOR == ':') {
			return trim(Z::command('ps ax | awk \'{ print $1 }\' | grep -e "^' . $pid . '$"', '', true, false), "\n") == $pid;
		} else {
			return preg_match('/\t?\s?' . $pid . '\t?\s?/', Z::command('tasklist /NH /FI "PID eq ' . $pid . '"'));
		}
	}
}
class Zls_Router_PathInfo extends Zls_Router {
	private $isPathinfo;
	public function __construct($isPathinfo = true) {
		parent::__construct();
		$this->isPathinfo = $isPathinfo;
	}
	/**
	 * @return \Zls_Route
	 */
	public function find() {
		$config = Zls::getConfig();
		$uri = $config->getRequest()->getPathInfo();
		$uri = trim($uri, '/') ?: z::get('_s');
		$_hmvcModule = $config->getCurrentDomainHmvcModuleNname();
		if (empty($uri) && empty($_hmvcModule)) {
			return $this->route->setFound(false);
		} else {
			if ($uriRewriter = $config->getUriRewriter()) {
				$uri = $uriRewriter->rewrite($uri);
			}
		}
		$_info = explode('/', $uri);
		$hmvcModule = current($_info);
		if (!$_hmvcModule) {
			if ($config->hmvcIsDomainOnly($hmvcModule)) {
				$hmvcModule = '';
			}
		} else {
			$hmvcModule = $_hmvcModule;
		}
		$hmvcModuleDirName = Zls::checkHmvc($hmvcModule, false);
		if (!$_hmvcModule && $hmvcModuleDirName && !$config->hmvcIsDomainOnly($hmvcModule)) {
			$uri = ltrim(substr($uri, strlen($hmvcModule)), '/');
		}
		$controller = $config->getDefaultController();
		$method = $config->getDefaultMethod();
		$subfix = $config->getMethodUriSubfix();
		if ($uri) {
			if ($subfix) {
				$methodPathArr = explode($subfix, $uri);
				if (Z::strEndsWith($uri, $subfix)) {
					if (false !== stripos($methodPathArr[0], '/')) {
						$controller = str_replace('/', '_', dirname($uri));
						$method = basename($methodPathArr[0]);
					} else {
						$method = basename($methodPathArr[0]);
					}
				} else {
					$controller = str_replace('/', '_', $uri);
				}
			} else {
				$methodPathArr = explode('/', $uri);
				if (count($methodPathArr) > 1) {
					$method = array_pop($methodPathArr);
					$controller = join('_', $methodPathArr);
				} else {
					$controller = $uri;
				}
			}
		}
		$controller = $config->getControllerDirName() . '_' . $controller;
		$methodAndParameters = explode($config->getMethodParametersDelimiter(), $method);
		$method = $config->getMethodPrefix() . current($methodAndParameters);
		array_shift($methodAndParameters);
		$parameters = $methodAndParameters;
		$hmvcModule = $hmvcModuleDirName ? $hmvcModule : '';
		return $this->route->setHmvcModuleName($hmvcModule)->setController($controller)->setMethod($method)->setArgs($parameters)->setFound(true);
	}
	public function url($action = '', $getData = [], $opt = ['subfix' => true, 'ishmvc' => false]) {
		$config = Z::config();
		if (!$action) {
			$route = z::config()->getRoute();
			$action = $route->getControllerShort() . '/' . $route->getMethodShort();
		} else {
			$action = ltrim($action, '/');
		}
		$isPathinfo = null !== $config->getRequest()->getPathInfo();
		$MethodUriSubfix = $config->getMethodUriSubfix();
		$SubfixStatus = $isPathinfo ? Z::arrayGet($opt, 'subfix', false) : false;
		$isHmvc = Z::arrayGet($opt, 'ishmvc', false);
		if (($action && $action !== '/') && true === $SubfixStatus && !Z::strEndsWith($action, $MethodUriSubfix)) {
			$action = $action . $MethodUriSubfix;
		}
		if (true === $isHmvc) {
			$hmvcModules = $config->getHmvcModules();
			$hmvcDirName = (bool) Z::arrayGet(
				$hmvcModules,
				$config->getRoute()->getHmvcModuleName(),
				null
			) ? $config->getRoute()->getHmvcModuleName() : '';
			if ($hmvcDirName) {
				$action = $hmvcDirName . '/' . $action;
			}
		}
		$hmvcModuleName = $config->getCurrentDomainHmvcModuleNname();
		if ($hmvcModuleName && $config->hmvcIsDomainOnly($hmvcModuleName)) {
			$action = preg_replace('|^' . $hmvcModuleName . '/?|', '/', $action);
		}
		$root = !Z::strBeginsWith($action, './');
		if (!$root) {
			$action = substr($action, 2);
		}
		$index = $config->getIsRewrite() ? ($root ? '/' : '') : ($root ? '/' . ZLS_INDEX_NAME : ZLS_INDEX_NAME . '/');
		if ($isPathinfo) {
			$url = $index . $action;
		} else {
			$url = $root ? $index . '?s=' . $action : '/' . $index . '?s=' . $action;
		}
		$url = rtrim($url, '/');
		$url = $index ? $url : ($action ? $url : $url . '/');
		if (!empty($getData)) {
			$url = $url . ($isPathinfo ? '?' : '&');
			foreach ($getData as $k => $v) {
				$url .= $k . '=' . urlencode($v) . '&';
			}
			$url = rtrim($url, '&');
		}
		if ($isPathinfo && $requestUri = Z::server('REQUEST_URI')) {
			$requestUri = Z::tap(explode($config->getRequest()->getPathInfo() ?: '/', $requestUri), function (&$v) {
				$v = Z::arrayGet($v, 0);
			});
			$url = Z::strBeginsWith($url, $requestUri) ? $url : $requestUri . $url;
		}
		return $url;
	}
}
abstract class Zls_Task_Single extends Zls_Task {
	public function _execute($args) {
		$class = get_class($this);
		$startTime = Z::microtime();
		$this->_log('Single Task [ ' . $class . ' ] start');
		$lockFilePath = Z::arrayGet($args, 'pid');
		if (!$lockFilePath) {
			$tempDirPath = Z::config()->getStorageDirPath();
			$key = md5(
				Z::config()->getAppDir() . Z::config()->getClassesDirName() . '/'
				. Z::config()->getTaskDirName() . '/' . str_replace('_', '/', get_class($this)) . '.php'
			);
			$lockFilePath = Z::realPathMkdir($tempDirPath . 'taskSingle', true, false, false, false) . $key . '.pid';
		}
		if (file_exists($lockFilePath)) {
			$pid = file_get_contents($lockFilePath);
			if ($this->pidIsExists($pid)) {
				$this->_log('Single Task [ ' . $class . ' ] is running with pid ' . $pid . ' , now exiting...');
				$this->_log('Single Task [ ' . $class . ' ] end , use time ' . (Z::microtime() - $startTime) . ' ms');
				Z::end();
			}
		}
		$this->_filePutContents($lockFilePath, getmypid());
		$this->_log('update pid file [ ' . $lockFilePath . ' ]');
		$this->execute($args);
		@unlink($lockFilePath);
		$this->_log('clean pid file [ ' . $lockFilePath . ' ]');
		$this->_log('Single Task [ ' . $class . ' ] end , use time ' . (Z::microtime() - $startTime) . ' ms');
	}
}
abstract class Zls_Task_Multiple extends Zls_Task {
	final private function getCurrentPids($lockFilePath) {
		if (file_exists($lockFilePath)) {
			return explode("\n", file_get_contents($lockFilePath));
		}
		return [];
	}
	public function _execute($args) {
		$class = get_class($this);
		$startTime = Z::microtime();
		$this->_log('Multiple Task [ ' . $class . ' ] start');
		$lockFilePath = Z::arrayGet($args, 'pid');
		if (!$lockFilePath) {
			$tempDirPath = Z::config()->getStorageDirPath();
			$key = md5(Z::config()->getAppDir() . Z::config()->getClassesDirName() . '/' . Z::config()->getTaskDirName() . '/' . str_replace('_', '/', get_class($this)) . '.php');
			$lockFilePath = Z::realPathMkdir($tempDirPath . 'taskMultiple', true, false, false, false) . $key . '.pid';
		}
		$alivedPids = [];
		if (file_exists($lockFilePath)) {
			$count = 0;
			$pids = explode("\n", file_get_contents($lockFilePath));
			foreach ($pids as $pid) {
				if ($pid = (int) $pid) {
					if ($this->pidIsExists($pid)) {
						$alivedPids[] = $pid;
						if (++$count > $this->getMaxCount() - 1) {
							$this->_log('Multiple Task [ ' . $class . ' ] reach max count : ' . $this->getMaxCount() . ' , now exiting...');
							$this->_log('Multiple Task [ ' . $class . ' ] end , use time ' . (Z::microtime() - $startTime) . ' ms');
							Z::end();
						}
					}
				}
			}
		}
		$getmypid = (string) getmypid();
		$alivedPids[] = $getmypid;
		$this->_filePutContents($lockFilePath, join("\n", $alivedPids));
		$this->_log('update pid file [ ' . $lockFilePath . ' ]');
		$this->execute($args);
		$currentPids = Z::arrayFilter($this->getCurrentPids($lockFilePath), function ($v) use ($getmypid) {
			return $v !== $getmypid;
		});
		if ($currentPids) {
			$this->_filePutContents($lockFilePath, join("\n", $currentPids));
			$this->_log('clean current pid [ ' . $getmypid . ' ]');
		} else {
			@unlink($lockFilePath);
			$this->_log('clean pid file [ ' . $lockFilePath . ' ]');
		}
		$this->_log('Multiple Task [ ' . $class . ' ] end , use time ' . (Z::microtime() - $startTime) . ' ms');
	}
	abstract protected function getMaxCount();
}
/**
 * @property Zls_Route $route
 */
abstract class Zls_Router {
	protected $route;
	public function __construct() {
		$this->route = new Zls_Route();
	}
	public function getType() {
		return get_called_class();
	}
	/**
	 * @return Zls_Route
	 */
	abstract public function find();
	abstract public function url($action = '', $getData = [], $opt = []);
	public function &route() {
		return $this->route;
	}
}
abstract class Zls_Exception extends \Exception {
	protected $errorMessage, $errorCode, $errorFile, $errorLine, $errorType, $trace, $httpStatusLine = 'HTTP/1.0 500 Internal Server Error', $exceptionName = 'Zls_Exception';
	public function __construct($errorMessage = '', $errorCode = 0, $errorType = 'Exception', $errorFile = '', $errorLine = '0') {
		parent::__construct($errorMessage, $errorCode);
		$this->errorMessage = $errorMessage;
		$this->errorCode = $errorCode;
		$this->errorType = $errorType;
		$this->errorFile = Z::realPath($errorFile);
		$this->errorLine = $errorLine;
		$this->trace = debug_backtrace(false);
		if (in_array($errorCode, [500, 404])) {
			Z::header(404 === $errorCode ? 'HTTP/1.1 404 Not Found' : 'HTTP/1.1 500 Internal Server Error');
		}
	}
	public function getErrorCode() {
		return $this->errorCode ? $this->errorCode : $this->getCode();
	}
	public function getTraceHtmlString() {
		return $this->getTraceString(false);
	}
	/**
	 * @param $isCli
	 *
	 * @return string
	 */
	private function getTraceString($isCli) {
		$trace = array_reverse($this->trace);
		$str = $isCli ? "[ Debug Backtrace ]\n" : '<div style="padding:10px">[ Debug Backtrace ]<br/>';
		if (empty($trace)) {
			return '';
		}
		$i = 1;
		foreach ($trace as $e) {
			$class = Z::arrayGet($e, 'class');
			$function = Z::arrayGet($e, 'function');
			if (Z::strBeginsWith($class, 'Zls_Exception') || in_array($class, ['Zls_Pipeline']) || (in_array($class, ['Z', 'Zls']) && in_array($function, ['{closure}', 'resultException']))) {
				continue;
			}
			$file = Z::safePath(Z::arrayGet($e, 'file'));
			$line = Z::arrayGet($e, 'line');
			$func = (!empty($class) ? "{$class}{$e['type']}{$e['function']}()" : "{$function}()");
			$str .= '' . ($i++) . ".{$func} " . ($line ? "[ line:{$line} {$file} ]" : '') . ($isCli ? "\n" : '<br/>');
		}
		$str .= $isCli ? "\n" : '</div>';
		return $str;
	}
	public function setHttpHeader() {
		if (!Z::isCli()) {
			Z::header($this->httpStatusLine);
		}
		return $this;
	}
	/**
	 * @return mixed|string
	 * @throws Exception
	 */
	public function __toString() {
		return $this->render(null, true);
	}
	/**
	 * 输出异常信息
	 */
	public function render($isJson = null, $return = false) {
		$isCli = Z::isCli() && !Z::isSwoole(true);
		if (is_null($isJson)) {
			$isJson = Z::isAjax();
		}
		if ($isJson) {
			$string = $this->renderJson();
		} elseif ($isCli) {
			$string = $this->renderCli();
		} else {
			$string = str_replace('</body>', $this->getTraceString($isCli) . '</body>', $this->renderHtml());
		}
		return !$return ? Z::end($string) : $string;
	}
	public function renderJson() {
		$render = Zls::getConfig()->getExceptionJsonRender();
		if (is_callable($render)) {
			return $render($this);
		}
		return '';
	}
	/**
	 * @return string
	 */
	public function renderCli() {
		$run = z::debug(false, false, true, false);
		return $this->exceptionName . ' [ ' . $this->getErrorType() . ' ]' . PHP_EOL . 'Environment: ' . $this->getEnvironment() . PHP_EOL . 'Line: ' . $this->getErrorLine() . '. ' . $this->getErrorFile() . PHP_EOL . 'Message: ' . $this->getErrorMessage() . PHP_EOL . 'Time: ' . date('Y/m/d H:i:s T') . PHP_EOL . 'WasteTime: ' . $run['runtime'] . PHP_EOL . 'Memory: ' . $run['memory'] . PHP_EOL . 'Trace: ' . $this->getTraceCliString() . PHP_EOL;
	}
	public function getErrorType() {
		return $this->errorType2string($this->errorCode);
	}
	public function errorType2string($errorType) {
		$value = $errorType;
		$levelNames = [
			E_ERROR => 'ERROR',
			E_WARNING => 'WARNING',
			E_PARSE => 'PARSE',
			E_NOTICE => 'NOTICE',
			E_CORE_ERROR => 'CORE_ERROR',
			E_CORE_WARNING => 'CORE_WARNING',
			E_COMPILE_ERROR => 'COMPILE_ERROR',
			E_COMPILE_WARNING => 'COMPILE_WARNING',
			E_USER_ERROR => 'USER_ERROR',
			E_USER_WARNING => 'USER_WARNING',
			E_USER_NOTICE => 'USER_NOTICE',
		];
		if (defined('E_STRICT')) {
			$levelNames[E_STRICT] = 'STRICT';
		}
		if (defined('E_DEPRECATED')) {
			$levelNames[E_DEPRECATED] = 'DEPRECATED';
		}
		if (defined('E_USER_DEPRECATED')) {
			$levelNames[E_USER_DEPRECATED] = 'USER_DEPRECATED';
		}
		if (defined('E_RECOVERABLE_ERROR')) {
			$levelNames[E_RECOVERABLE_ERROR] = 'RECOVERABLE_ERROR';
		}
		$levels = [];
		if (E_ALL == ($value & E_ALL)) {
			$levels[] = 'E_ALL';
			$value &= ~E_ALL;
		}
		foreach ($levelNames as $level => $name) {
			if (($value & $level) == $level) {
				$levels[] = $name;
			}
		}
		if (empty($levelNames[$this->errorCode])) {
			return $this->errorType ? $this->errorType : 'General Error';
		}
		return join(' | ', $levels);
	}
	/**
	 * @return array|mixed|null|string
	 */
	public function getEnvironment() {
		return Z::config()->getEnvironment();
	}
	public function getErrorLine() {
		return $this->errorLine ? $this->errorLine : ($this->errorFile ? $this->errorLine : $this->getLine());
	}
	/**
	 * @param bool $safePath
	 *
	 * @return string
	 */
	public function getErrorFile($safePath = false) {
		$file = $this->errorFile ? $this->errorFile : $this->getFile();
		return $safePath ? Z::safePath($file) : $file;
	}
	public function getErrorMessage() {
		return $this->errorMessage ? $this->errorMessage : $this->getMessage();
	}
	/**
	 * @return string
	 */
	public function getTraceCliString() {
		return $this->getTraceString(true);
	}
	public function renderHtml() {
		$run = z::debug(false, false, true, false);
		return '<html><meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0"><body style="line-height:30px;padding:0;margin:0;background:#0C8611;color:whitesmoke;font-family:\'Courier New\',monospace;font-size:18px;">'
		. '<div style="padding:10px;background:#104411;color:#4CAF50;font-size:25px;font-weight:bold;">' . $this->exceptionName . ' - [ ' . $this->getErrorType() . ' ] </div>'
		. '<div style="padding:10px;color:yellow;">'
		. '<strong>Environment: </strong>
' . $this->getEnvironment() . '<br/>'
		. '<strong>Line: </strong>' . $this->getErrorLine() . ' [ ' . $this->getErrorFile(true) . ' ]<br/>'
		. '<strong>Message: </strong>' . htmlspecialchars($this->getErrorMessage()) . '</br>'
		. '<strong>Time: </strong>' . date('Y/m/d H:i:s T') . '</br>'
			. '<strong>WasteTime: </strong>' . $run['runtime'] . '</br>'
			. '<strong>Memory: </strong>' . $run['memory'] . '</div>'
			. '</body></html>';
	}
}
abstract class Zls_Session implements \SessionHandlerInterface {
	protected $config;
	/**
	 * Zls_Session constructor
	 *
	 * @param $configFileName
	 */
	public function __construct($configFileName = '') {
		if (is_array($configFileName)) {
			$this->config = $configFileName;
		} elseif ($configFileName) {
			$this->config = Z::config($configFileName);
		}
	}
	abstract public function init($id);
	abstract public function swooleInit($id);
	abstract public function swooleWrite($id, $data);
	abstract public function swooleRead($id);
	abstract public function swooleDestroy($id);
	abstract public function swooleGc($maxlifetime);
	abstract public function open($path, $name);
	abstract public function close();
	abstract public function read($key);
	abstract public function write($key, $val);
	abstract public function destroy($key);
	abstract public function gc($maxlifetime);
}
class Zls_Exception_Exit extends Exception {
}
class Zls_Exception_404 extends Zls_Exception {
	protected $exceptionName = 'Zls_Exception_404';
	protected $httpStatusLine = 'HTTP/1.0 404 Not Found';
	public function __construct($errorMessage = '', $errorCode = 404, $errorType = 'Exception', $errorFile = '', $errorLine = '0') {
		parent::__construct($errorMessage, $errorCode, $errorType = 'Exception', $errorFile, $errorLine);
	}
}
class Zls_Exception_500 extends Zls_Exception {
	protected $exceptionName = 'Zls_Exception_500';
	protected $httpStatusLine = 'HTTP/1.0 500 Internal Server Error';
	public function __construct($errorMessage = '', $errorCode = 500, $errorType = 'Exception', $errorFile = '', $errorLine = '0') {
		if ('Class \'Zls_Dao\' not found' === $errorMessage) {
			$errorMessage = 'Warning: command not installed, Please install "composer require zls/dao"' . PHP_EOL;
		}
		parent::__construct($errorMessage, $errorCode, $errorType, $errorFile, $errorLine);
	}
}
class Zls_Exception_Database extends Zls_Exception {
	protected $exceptionName = 'Zls_Exception_Database';
	protected $httpStatusLine = 'HTTP/1.0 500 Internal Server Error';
	public function __construct($errorMessage = '', $errorCode = 500, $errorType = 'Exception', $errorFile = '', $errorLine = '0') {
		parent::__construct($errorMessage, $errorCode, $errorType, $errorFile, $errorLine);
	}
}
class Zls_Request_Default implements Zls_Request {
	public function __construct() {
		if (!$pathInfo = Z::server('PATH_INFO', Z::server('REDIRECT_PATH_INFO'))) {
			if ($requestUri = Z::server('REQUEST_URI', '')) {
				$REQUEST_URI = Z::server('REQUEST_URI', '');
				if (Z::strBeginsWith($REQUEST_URI, '//')) {
					$REQUEST_URI = ltrim($REQUEST_URI, '/');
				}
				$pathInfo = parse_url($REQUEST_URI, PHP_URL_PATH);
			}
		}
		$queryString = Z::server('QUERY_STRING', '');
		Z::setGlobalData([
			'pathInfo' => $pathInfo,
			'queryString' => $queryString,
		], ZLS_PREFIX);
	}
	public function getPathInfo() {
		return Z::getGlobalData(ZLS_PREFIX . 'pathInfo');
	}
	public function setPathInfo($pathInfo) {
		Z::setGlobalData(ZLS_PREFIX . 'pathInfo', $pathInfo);
		return $this;
	}
	public function getQueryString() {
		return Z::getGlobalData(ZLS_PREFIX . 'queryString');
	}
	public function setQueryString($queryString) {
		Z::setGlobalData(ZLS_PREFIX . 'queryString', $queryString);
		return $this;
	}
}
/**
 * Class Zls_View
 * @method string loadJs($viewName, $data = [], $return = false)
 * @method string loadCss($viewName, $data = [], $return = false)
 * @method string loadHtml($viewName, $data = [], $return = false)
 */
class Zls_View {
	public function __call($name, $args) {
		Z::throwIf(!Z::strBeginsWith($name, 'load'), 500, $name . ' unknown type of method [ ' . $name . ' ]');
		$args[3] = strtolower(substr($name, 4));
		return call_user_func(function ($args) {
			return $this->load($args[0], z::arrayGet($args, 1, []), z::arrayGet($args, 2, false), $args[3]);
		}, $args);
	}
	public function add($key, $value = []) {
		$ck = __CLASS__;
		$old = Z::getGlobalData($ck, []);
		if (is_array($key)) {
			foreach ($key as $k => $v) {
				if (!Z::arrayKeyExists($k, $old)) {
					$old[$k] = $v;
				}
			}
		} else {
			if (!Z::arrayKeyExists($key, $old)) {
				$old[$key] = $value;
			}
		}
		Z::setGlobalData($ck, $old);
		return $this;
	}
	public function set($key, $value = []) {
		$ck = __CLASS__;
		$old = Z::getGlobalData($ck, []);
		if (is_array($key)) {
			foreach ($key as $k => $v) {
				$old[$k] = $v;
			}
		} else {
			$old[$key] = $value;
		}
		Z::setGlobalData($ck, $old);
		return $this;
	}
	/**
	 * 加载一个视图
	 *
	 * @param string $viewName 视图名称
	 * @param array  $data     视图中可以使用的数据
	 * @param bool   $return   是否返回视图内容
	 *
	 * @return string
	 */
	public function load($viewName, $data = [], $return = false, $suffix = 'php') {
		$config = Z::config();
		$path = $config->getAppDir() . $config->getViewsDirName() . '/' . $viewName . '.' . $suffix;
		$hmvcModules = $config->getHmvcModules();
		$hmvcDirName = Z::arrayGet($hmvcModules, $config->getRoute()->getHmvcModuleName(), '');
		if ($hmvcDirName) {
			$hmvcPath = Z::realPath($config->getPrimaryAppDir() . $config->getHmvcDirName() . '/' . $hmvcDirName);
			$trace = debug_backtrace();
			$calledIsInHmvc = false;
			$appPath = Z::realPath($config->getAppDir());
			foreach ($trace as $t) {
				$filepath = Z::arrayGet($t, 'file', '');
				if (!empty($filepath)) {
					$filepath = Z::realPath($filepath);
					$checkList = ['load', 'runWeb', 'message', 'redirect'];
					$function = Z::arrayGet($t, 'function', '');
					if (($filepath && in_array($function, $checkList) && 0 === strpos($filepath, $appPath) && 0 === strpos($filepath, $hmvcPath)) || 'handle' == $function) {
						$calledIsInHmvc = true;
						break;
					} elseif (!in_array($function, $checkList)) {
						break;
					}
				}
			}
			if (!$calledIsInHmvc) {
				$path = $config->getPrimaryAppDir() . $config->getViewsDirName() . '/' . $viewName . '.php';
			}
		}
		return $this->loadRaw($path, $data, $return);
	}
	/**
	 * @param       $path
	 * @param array $data
	 * @param bool  $return
	 *
	 * @return string
	 */
	public function loadRaw($path, $data = [], $return = false) {
		Z::throwIf(!file_exists($path), 500, 'view file : [ ' . $path . ' ] not found', 'ERROR');
		$data = array_merge(Z::getGlobalData(__CLASS__) ?: [], $data);
		if (!empty($data)) {
			extract($data);
		}
		if ($return) {
			@ob_start();
			include $path;
			$html = @ob_get_clean();
			return $html;
		} else {
			include $path;
			return '';
		}
	}
	/**
	 * 加载主项目的视图
	 *
	 * @param string $viewName 主项目视图名称
	 * @param array  $data     视图中可以使用的数据
	 * @param bool   $return   是否返回视图内容
	 *
	 * @return string
	 */
	public function loadParent($viewName, $data = [], $return = false) {
		$config = Z::config();
		$path = $config->getPrimaryAppDir() . $config->getViewsDirName() . '/' . $viewName . '.php';
		return $this->loadRaw($path, $data, $return);
	}
}
class Zls_CliArgs {
	private $args;
	public function __construct() {
		$args = Z::getOpt();
		$this->args = empty($args) ? [] : $args;
	}
	public function get($key = null, $default = null) {
		if (empty($key)) {
			return $this->args;
		}
		return Z::arrayGet($this->args, $key, $default);
	}
}
class Zls_Route {
	private $type;
	private $found = false;
	private $controller;
	private $method;
	private $args;
	private $hmvcModuleName;
	public function __construct() {
		$this->args = [];
	}
	public function getType() {
		return $this->type;
	}
	public function setType($type) {
		$this->type = $type;
		return $this;
	}
	public function getHmvcModuleName() {
		return $this->hmvcModuleName;
	}
	public function setHmvcModuleName($hmvcModuleName) {
		$this->hmvcModuleName = $hmvcModuleName;
		return $this;
	}
	public function found() {
		return $this->found;
	}
	public function setFound($found) {
		$this->found = $found;
		return $this;
	}
	public function getControllerShort() {
		return str_replace('_', '/', preg_replace('/^' . Z::config()->getControllerDirName() . '_/', '', $this->getController()));
	}
	public function getController() {
		return $this->controller;
	}
	public function setController($controller) {
		$this->controller = $controller;
		return $this;
	}
	public function getMethodShort() {
		return preg_replace('/^' . Z::config()->getMethodPrefix() . '/', '', $this->getMethod());
	}
	public function getMethod() {
		return $this->method;
	}
	public function setMethod($method) {
		$this->method = $method;
		return $this;
	}
	public function getArgs() {
		return $this->args;
	}
	public function setArgs(array $args) {
		$this->args = $args;
		return $this;
	}
}
class Zls_SeparationRouter extends Zls_Route {
	/**
	 * @param $route
	 * @param $isHmvcModule
	 *
	 * @return bool|string
	 */
	public function find($route, $isHmvcModule) {
		$arg = explode('_', $route);
		$hmvcModule = array_shift($arg);
		if ($hmvcModule !== $isHmvcModule) {
			return false;
		}
		$document = join('/', $arg);
		$appPath = ZLS_APP_PATH . '../';
		$path = $appPath . 'router/' . $hmvcModule . '.json';
		$config = Z::config();
		if (file_exists($path)) {
			$data = @json_decode(file_get_contents($path), true);
			Z::throwIf(empty($data), 404, 'invalid file format or conte file : [ ' . Z::safePath($path) . ' ]');
			$defaultMethod = Z::arrayGet($data, 'default');
			if (!$document || $document === $config->getDefaultController()) {
				$document = $defaultMethod;
			}
			$routerMatch = [];
			$router = Z::arrayGet($data, 'routerList');
			$viewPath = '';
			$routerData = $beforeData = [];
			$document = strtolower($document);
			$static = Z::arrayGet($data, 'static');
			if ((bool) $router) {
				foreach ($router as $_router => $value) {
					if (!isset($value['view']) && !isset($value['viewFile'])) {
						continue;
					} elseif (preg_match('/^' . str_replace('/', '\/', $_router) . '$/', $document, $routerMatch)) {
						$viewPath = Z::arrayGet($value, 'view') ?: Z::arrayGet($value, 'viewFile') . '/' . $document;
						$routerData = $value;
						break;
					} elseif ((bool) $defaultMethod && preg_match('/^' . str_replace('/', '\/', $_router) . '$/', $document . '/' . $defaultMethod, $routerMatch)) {
						$_viewPath = Z::arrayGet($value, 'view') ?: Z::arrayGet($value, 'viewFile') . '/' . $document . '/' . $defaultMethod;
						if (is_file($appPath . $_viewPath)) {
							$viewPath = $_viewPath;
							$routerData = $value;
							break;
						}
					}
				}
			}
			if (!$viewPath) {
				return false;
			}
			if (is_dir($view = $appPath . $viewPath)) {
				$view = $view . '/' . $defaultMethod;
			}
			Z::throwIf(!file_exists($view), 404, 'view file : [ ' . Z::safePath($view) . ' ] not found');
			$pathPrefix = explode('/', $viewPath);
			$document = array_pop($pathPrefix);
			$pathPrefix = array_shift($pathPrefix);
			if ($before = Z::arrayGet($data, 'before')) {
				$rule = explode(':', $before);
				$before = Z::business(Z::arrayGet($rule, 0));
				if ($beforeMethod = Z::arrayGet($rule, 1)) {
					$beforeData['globalData'] = $before->$beforeMethod($routerMatch, $document, $pathPrefix, $hmvcModule);
				}
			}
			if ($before = Z::arrayGet($routerData, 'before')) {
				$rule = explode(':', $before);
				$before = Z::business(Z::arrayGet($rule, 0));
				if ($beforeMethod = Z::arrayGet($rule, 1)) {
					$beforeData['data'] = $before->$beforeMethod($routerMatch, $document, $pathPrefix, $hmvcModule);
				}
			}
			if (true === $static) {
				$html = \file_get_contents($view);
			} else {
				$html = Z::view()->loadRaw($view, ['beforeData' => $beforeData], true);
			}
			if ($after = Z::arrayGet($data, 'after')) {
				$rule = explode(':', $after);
				$after = Z::business(Z::arrayGet($rule, 0));
				if ($afterMethod = Z::arrayGet($rule, 1)) {
					$html = $after->$afterMethod($html, $routerMatch, $document, $pathPrefix, $hmvcModule);
				}
			}
			if ($after = Z::arrayGet($routerData, 'after')) {
				$rule = explode(':', $after);
				$after = Z::business(Z::arrayGet($rule, 0));
				if ($afterMethod = Z::arrayGet($rule, 1)) {
					$content = $after->$afterMethod($html, $routerMatch, $document, $pathPrefix, $hmvcModule, $document);
					if ($content) {
						$html = $content;
					}
				}
			}
			return $html;
		}
		return false;
	}
}
/**
 * @property Zls_Exception_Handle $exceptionHandle
 * @method Zls_Config                         setHmvcModules(array $hmvcs)
 * @method Zls_Config                         setEnvironment(string $environment)
 * @method Zls_Config                         setShowError(boolean $showError)
 * @method Zls_Config                         setTraceStatus($e)
 * @method Zls_Config                         setApiDocToken(string $token)
 * @method Zls_Config                         setIsRewrite(boolean $isRewrite)
 * @method Zls_Config                         setSeparationRouter(boolean $separationRouter)
 * @method Zls_Config                         setDefaultController(string $defaultController)
 * @method Zls_Config                         setDefaultMethod($e)
 * @method Zls_Config                         setMethodPrefix($e)
 * @method Zls_Config                         setMethodUriSubfix($e)
 * @method Zls_Config                         setMethodParametersDelimiter($e)
 * @method Zls_Config                         setExceptionHandle($e)
 * @method Zls_Config                         setOutputJsonRender($e)
 * @method Zls_Config                         setCommands($e)
 * @method Zls_Config                         setHmvcDirName($e)
 * @method Zls_Config                         setLogsMaxDay($e)
 * @method Zls_Config                         setHttpMiddleware(array $middleware)
 * @method Zls_Config                         setDatabaseMiddleware(array $middleware)
 * @method string                       getBeanDirName()
 * @method string                       getExceptionMemoryReserveSize()
 * @method string                       getExceptionLevel()
 * @method string                       getAppDir()
 * @method string                       getClassesDirName()
 * @method string                       getControllerDirName()
 * @method string                       getCookiePrefix()
 * @method string                       getCacheConfig()
 * @method array                        getHmvcModules()
 * @method string                       getTaskDirName()
 * @method string                       getPrimaryAppDir()
 * @method string                       getMethodPrefix()
 * @method string                       getBusinessDirName()
 * @method string                       getDaoDirName()
 * @method string                       getModelDirName()
 * @method string                       getHmvcDirName()
 * @method string                       getApiDocToken()
 * @method string                       getFunctionsDirName()
 * @method string                       getDefaultController()
 * @method string                       getDefaultMethod()
 * @method string                       getLibraryDirName()
 * @method string                       getMethodUriSubfix()
 * @method string                       getConfigDirName()
 * @method bool                         getExceptionControl()
 * @method \Zls_Maintain_Handle_Default getMaintainModeHandle()
 * @method \Zls_Session                 getSessionHandle()
 * @method array                        getCommands()
 * @method bool                         getIsRewrite()
 * @method array                        getHttpMiddleware()
 * @method array                        getDatabaseMiddleware()
 */
class Zls_Config {
	private static $alias = [];
	private $context;
	private $appDir = '';
	private $primaryAppDir = '';
	private $indexDir = '';
	private $commands = [];
	private $classesDirName = 'classes';
	private $hmvcDirName = 'hmvc';
	private $libraryDirName = 'library';
	private $functionsDirName = 'functions';
	private $storageDirPath = '';
	private $viewsDirName = 'views';
	private $configDirName = 'config';
	private $controllerDirName = 'Controller';
	private $businessDirName = 'Business';
	private $daoDirName = 'Dao';
	private $beanDirName = 'Bean';
	private $modelDirName = 'Model';
	private $taskDirName = 'Task';
	private $defaultController = 'Index';
	private $defaultMethod = 'index';
	private $methodPrefix = 'z_';
	private $methodUriSubfix = '.go';
	private $methodParametersDelimiter = '-';
	private $logsSubDirNameFormat = 'Y-m-d/H'; // todo 后期移除
	private $exceptionLevel = '';
	private $exceptionControl = true;
	private $cookiePrefix = '';
	private $backendServerIpWhitelist = [];
	private $isRewrite = true;
	public $runState = true;
	private $request;
	private $showError;
	private $traceStatus = false;
	private $routersContainer = [];
	private $packageMasterContainer = [];
	private $packageContainer = [];
	private $loggerWriters = [];
	private $logsMaxDay = 10;
	private $uriRewriter;
	private $exceptionHandle;
	private $route;
	private $environment = 'production';
	private $hmvcModules = [];
	private $isMaintainMode;
	private $maintainIpWhitelist = [];
	private $maintainModeHandle;
	private $databseConfig;
	private $cacheHandles = [];
	private $cacheConfig;
	private $sessionConfig;
	private $sessionHandle;
	private $hvmcDomain;
	private $dataCheckRules;
	private $outputJsonRender;
	private $exceptionJsonRender;
	private $zMethods = [];
	private $encryptKey;
	private $apiDocToken = '';
	private $useMyid = false;
	private $exceptionMemoryReserveSize = 256000;
	private $separationRouter = false;
	private $traceStatusCallBack = null;
	private $hmvcDomains = ['enable' => false, 'domains' => []];
	private $httpMiddleware = [];
	private $databaseMiddleware = [];
	private $clientIpConditions
	= [
		'source' => ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'],
		'check' => ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP'],
	];
	private $hasSwooleContext;
	/**
	 * @return array
	 */
	public function getAlias() {
		return self::$alias;
	}
	public function getMaintainIpWhitelist() {
		return $this->getSysConfig($this->maintainIpWhitelist ?: null, 'ipWhitelist', []);
	}
	/**
	 * @param array $maintainIpWhitelist
	 *
	 * @return $this
	 */
	public function setMaintainIpWhitelist(array $maintainIpWhitelist) {
		$this->maintainIpWhitelist = $maintainIpWhitelist;
		return $this;
	}
	public function getSysConfig($value, $key, $default = '') {
		if (is_null($value)) {
			$value = Z::config()->find('zls') ? Z::config('zls.' . $key) : $default;
		}
		return $value;
	}
	/**
	 * 按照包的顺序查找配置文件
	 *
	 * @param string $filename
	 * @param string $ext
	 *
	 * @return string
	 */
	public function find($filename, $ext = '.php') {
		foreach ($this->getPackages() as $packagePath) {
			$path = $packagePath . $this->getConfigDirName() . '/';
			$filePath = $path . $this->getEnvironment() . '/' . $filename . $ext;
			$fileDefaultPath = $path . 'default/' . $filename . $ext;
			if (file_exists($filePath)) {
				return $filePath;
			} elseif (file_exists($fileDefaultPath)) {
				return $fileDefaultPath;
			}
		}
		return '';
	}
	public function getPackages() {
		return array_merge($this->packageMasterContainer, $this->packageContainer);
	}
	public function getEnvironment() {
		if (empty($this->environment)) {
			$this->environment = ($env = (($cliEnv = Z::getOpt('env')) ? $cliEnv : Z::server('ENVIRONMENT'))) ? $env : 'production'; //'development'
		}
		return $this->environment;
	}
	public function getApplicationDir() {
		return $this->appDir;
	}
	public function getShowError() {
		return $this->getSysConfig($this->showError, 'showError');
	}
	/**
	 * 设置别名
	 *
	 * @param array $alias
	 *
	 * @return $this
	 */
	public function setAlias($alias) {
		self::$alias = $alias;
		return $this;
	}
	public function __get($name) {
		return $this->$name;
	}
	public function __set($name, $value) {
		$this->$name = $value;
		return $this;
	}
	public function setExceptionControl($exceptionControl = true) {
		$this->exceptionControl = $exceptionControl;
		return $this;
	}
	public function getCurrentDomainHmvcModuleNname() {
		if (!$this->hmvcDomains['enable']) {
			return false;
		} elseif (!is_null($this->hvmcDomain)) {
			return $this->hvmcDomain;
		}
		$_domain = Z::server('http_host');
		$domain = explode('.', $_domain);
		$length = count($domain);
		$topDomain = '';
		if ($length >= 2) {
			$topDomain = $domain[$length - 2] . '.' . $domain[$length - 1];
		}
		foreach ($this->hmvcDomains['domains'] as $prefix => $hvmc) {
			$hvmcDomain = ($hvmc['isFullDomain'] ? $prefix : ($prefix . '.' . $topDomain));
			if (
				(true === z::arrayGet($hvmc, 'isRegEx'))
				&& (preg_match('/^' . $hvmcDomain . '$/', $_domain))
			) {
				$this->hvmcDomain = $hvmc['enable'] ? $hvmc['hmvcModuleName'] : false;
			} elseif ($hvmcDomain == $_domain) {
				$this->hvmcDomain = $hvmc['enable'] ? $hvmc['hmvcModuleName'] : false;
			}
			if (!is_null($this->hvmcDomain)) {
				return $this->hvmcDomain;
			}
		}
		return '';
	}
	public function hmvcIsDomainOnly($hmvcModuleName) {
		if (!$hmvcModuleName || !$this->hmvcDomains['enable']) {
			return false;
		}
		foreach ($this->hmvcDomains['domains'] as $hvmc) {
			if ($hmvcModuleName == $hvmc['hmvcModuleName']) {
				return $hvmc['domainOnly'];
			}
			return false;
		}
		return false;
	}
	public function setHmvcDomains($hmvcDomains) {
		if (is_string($hmvcDomains)) {
			$this->hmvcDomains = Z::config($hmvcDomains, false);
		} elseif (is_array($hmvcDomains)) {
			$this->hmvcDomains = $hmvcDomains;
		}
		return $this;
	}
	public function getEncryptKey() {
		$key = $this->getEnvironment();
		if (!empty($this->encryptKey[$key])) {
			return $this->encryptKey[$key];
		} elseif (!empty($this->encryptKey['default'])) {
			return $this->encryptKey['default'];
		}
		return '73zls';
	}
	public function setEncryptKey($encryptKey) {
		$encryptFile = Z::config()->find($encryptKey);
		if ((bool) $encryptFile) {
			$encryptKey = Z::config($encryptKey, false);
		}
		if (is_array($encryptKey)) {
			$this->encryptKey = $encryptKey;
		} else {
			$this->encryptKey = [
				'default' => $encryptKey,
			];
		}
		return $this;
	}
	/**
	 * 扩展核心
	 *
	 * @param              $methodName
	 * @param string|array $method
	 *
	 * @return $this
	 */
	public function setZMethods($methodName, $method = null) {
		if (is_array($methodName)) {
			$this->zMethods = array_merge($this->zMethods, $methodName);
		} else {
			$this->zMethods[$methodName] = $method;
		}
		return $this;
	}
	public function getExceptionJsonRender() {
		if (!$this->exceptionJsonRender) {
			$this->exceptionJsonRender = function (\Exception $e) {
				$run = Z::debug(null, false, true, false);
				return @json_encode(['environment' => $e->getEnvironment(), 'file' => $e->getErrorFile(), 'line' => $e->getErrorLine(), 'msg' => $e->getErrorMessage(), 'type' => $e->getErrorType(), 'code' => 0, 'errorCode' => $e->getErrorCode(), 'time' => date('Y/m/d H:i:s T'), 'wasteTime' => $run['runtime'], 'memory' => $run['memory'], 'trace' => array_filter(explode("\n", $e->getTraceCliString()))]);
			};
		}
		return $this->exceptionJsonRender;
	}
	public function getOutputJsonRender() {
		z::header('Content-Type: application/json; charset=UTF-8');
		if (empty($this->outputJsonRender)) {
			$this->outputJsonRender = function () {
				$args = func_get_args();
				if (is_array($code = Z::arrayGet($args, 0, ''))) {
					$args = $code;
					$code = $args[0];
				}
				$message = Z::arrayGet($args, 1, '');
				$data = Z::arrayGet($args, 2, '');
				$json = json_encode(
					['code' => $code, 'msg' => $message, 'data' => $data],
					JSON_UNESCAPED_UNICODE + JSON_UNESCAPED_SLASHES
				);
				return Z::tap($json, function ($json) use ($args) {
					if (Z::arrayGet($args, 3, false)) {
						Z::end($json);
					}
				});
			};
		}
		return $this->outputJsonRender;
	}
	/**
	 * @param $dataCheckRules
	 *
	 * @return $this
	 */
	public function setDataCheckRules($dataCheckRules) {
		$this->dataCheckRules = is_array($dataCheckRules) ? $dataCheckRules : Z::config($dataCheckRules, false);
		return $this;
	}
	public function getTraceStatus($type = '') {
		return Z::tap($this->traceStatus, function (&$state) use ($type) {
			if (is_array($state)) {
				$state = Z::arrayGet($state, $type, true);
			}
		});
	}
	/**
	 * @param string $key
	 *
	 * @return mixed
	 */
	public function getCacheHandle($key = '') {
		$fileCacheClass = 'Zls_Cache_File';
		if (empty($this->cacheConfig)) {
			$this->cacheConfig = Z::config('cache')
			?: [
				'default_type' => 'file',
				'drivers' => ['file' => ['class' => $fileCacheClass, 'config' => Z::config()->getStorageDirPath() . 'cache/']],
			];
		}
		if (is_array($key)) {
			return z::factory($key['class'], false, false, [$key['config']]);
		} else {
			$key = $key ? $key : $this->cacheConfig['default_type'];
			Z::throwIf(!Z::arrayKeyExists("drivers.$key", $this->cacheConfig), 500, 'unknown cache type [ ' . $key . ' ]', 'ERROR');
			$config = $this->cacheConfig['drivers'][$key]['config'];
			if (!$className = Z::arrayGet($this->cacheConfig, 'drivers.' . $key . '.class')) {
				// 没有缓存类默认文件缓存
				$className = $fileCacheClass;
			}
			if (!Z::arrayKeyExists($key, $this->cacheHandles)) {
				$this->cacheHandles[$key] = z::factory($className, false, false, [$config]);
			}
			return $this->cacheHandles[$key];
		}
	}
	public function getStorageDirPath() {
		return empty($this->storageDirPath) ? $this->getPrimaryAppDir() . 'storage/' : $this->storageDirPath;
	}
	public function setStorageDirPath($storageDirPath) {
		$this->storageDirPath = Z::realPath($storageDirPath, true);
		return $this;
	}
	public function setCacheConfig($cacheConfig) {
		$this->cacheHandles = [];
		if (is_string($cacheConfig)) {
			$this->cacheConfig = Z::config($cacheConfig, false);
		} elseif (is_array($cacheConfig)) {
			$this->cacheConfig = $cacheConfig;
		}
		return $this;
	}
	/**
	 * 设置session托管
	 *
	 * @param $sessionHandle
	 *
	 * @return $this
	 */
	public function setSessionHandle($sessionHandle) {
		if ($sessionHandle instanceof Zls_Session) {
			$this->sessionHandle = $sessionHandle;
		} else {
			$this->sessionHandle = Z::config($sessionHandle, false);
		}
		return $this;
	}
	public function getSessionConfig() {
		if (empty($this->sessionConfig)) {
			$this->sessionConfig = [
				'autostart' => false,
				'cookie_path' => '/',
				'cookie_domain' => Z::arrayGet(explode(':', Z::server('HTTP_HOST')), 0, Z::server('HTTP_HOST')),
				'session_name' => 'ZLS',
				'lifetime' => 86400,
				'session_save_path' => null,
			];
		}
		return $this->sessionConfig;
	}
	/**
	 * @param $sessionConfig
	 *
	 * @return $this
	 */
	public function setSessionConfig($sessionConfig) {
		if (is_array($sessionConfig)) {
			$this->sessionConfig = $sessionConfig;
		} else {
			$this->sessionConfig = Z::config($sessionConfig, false);
		}
		return $this;
	}
	public function getDatabaseConfig($group = null) {
		if (empty($group)) {
			return $this->databseConfig;
		} else {
			return Z::arrayKeyExists($group, $this->databseConfig) ? $this->databseConfig[$group] : [];
		}
	}
	public function setDatabaseConfig($databseConfig) {
		$this->databseConfig = is_array($databseConfig) ? $databseConfig : Z::config($databseConfig);
		return $this;
	}
	public function setMaintainModeHandle(Zls_Maintain_Handle $maintainModeHandle) {
		$this->maintainModeHandle = $maintainModeHandle;
		return $this;
	}
	public function getIsMaintainMode() {
		return $this->getSysConfig($this->isMaintainMode, 'maintainMode');
	}
	/**
	 * @return Zls_Uri_Rewriter
	 */
	public function getUriRewriter() {
		if (!$this->uriRewriter) {
			$this->uriRewriter = new \Zls_Uri_Rewriter_Default();
		}
		return $this->uriRewriter;
	}
	public function setUriRewriter(Zls_Uri_Rewriter $uriRewriter) {
		$this->uriRewriter = $uriRewriter;
		return $this;
	}
	/**
	 * 如果服务器是ngix之类代理转发请求到后端apache运行的PHP
	 * 那么这里应该设置信任的nginx所在服务器的ip<br>
	 * nginx里面应该设置 X_FORWARDED_FOR server变量来表示真实的客户端IP
	 * 不然通过Z::clientIp()是获取不到真实的客户端IP的
	 *
	 * @param array $backendServerIpWhitelist
	 *
	 * @return \Zls_Config
	 */
	public function setBackendServerIpWhitelist(array $backendServerIpWhitelist) {
		$this->backendServerIpWhitelist = $backendServerIpWhitelist;
		return $this;
	}
	/**
	 * @param array $funciontsFileNameArray
	 *
	 * @return $this
	 */
	public function addAutoloadFunctions(array $funciontsFileNameArray) {
		foreach ($funciontsFileNameArray as $functionsFileName) {
			Z::functions($functionsFileName);
		}
		return $this;
	}
	/**
	 * @return \Zls_Route
	 */
	public function getRoute() {
		return empty($this->route) ? new \Zls_Route() : $this->route;
	}
	/**
	 * 设置错误级别
	 *
	 * @param $exceptionLevel
	 *
	 * @return $this
	 */
	public function setExceptionLevel($exceptionLevel) {
		$this->exceptionLevel = $exceptionLevel;
		return $this;
	}
	public function getIndexDir() {
		if (empty($this->indexDir)) {
			$this->indexDir = ZLS_PATH;
		}
		return $this->indexDir;
	}
	public function setIndexDir($indexDir) {
		$this->indexDir = Z::realPath($indexDir) . '/';
		return $this;
	}
	public function setLoggerWriters(Zls_Logger $loggerWriters) {
		$this->loggerWriters = $loggerWriters;
		return $this;
	}
	public function addMasterPackages(array $packagesPath) {
		foreach ($packagesPath as $packagePath) {
			$this->addMasterPackage($packagePath);
		}
		return $this;
	}
	public function addMasterPackage($packagePath) {
		$packagePath = realpath($packagePath) . '/';
		if (!in_array($packagePath, $this->packageMasterContainer)) {
			array_push($this->packageMasterContainer, $packagePath);
			if (file_exists($library = $packagePath . $this->getLibraryDirName() . '/')) {
				array_push($this->packageMasterContainer, $library);
			}
		}
		return $this;
	}
	public function addPackages(array $packagesPath) {
		foreach ($packagesPath as $packagePath) {
			$this->addPackage($packagePath);
		}
		return $this;
	}
	public function addPackage($packagePath) {
		$packagePath = Z::realPath($packagePath) . '/';
		if (!in_array($packagePath, $this->packageContainer)) {
			array_push($this->packageContainer, $packagePath);
			if (file_exists($library = $packagePath . $this->getLibraryDirName() . '/')) {
				array_push($this->packageContainer, $library);
			}
		}
		return $this;
	}
	/**
	 * @param $method
	 * @param $args
	 *
	 * @return string|bool
	 */
	public function __call($method, $args) {
		if (Z::strBeginsWith($method, 'get')) {
			$argName = lcfirst(str_replace('get', '', $method));
			return $this->$argName;
		} elseif (Z::strBeginsWith($method, 'set')) {
			$argName = lcfirst(str_replace('set', '', $method));
			$this->$argName = 1 === count($args) ? $args[0] : $args;
			return $this;
		}
		Z::throwIf(true, 500, 'Call to undefined method Zls_Config::' . $method . '()');
		return false;
	}
	/**
	 * 加载项目目录下的bootstrap.php配置
	 */
	public function bootstrap() {
		if (file_exists($bootstrap = $this->getAppDir() . 'bootstrap.php')) {
			if (!Z::isSwoole()) {
				Z::includeOnce($bootstrap);
			} else {
				Z::swooleBootstrap($this->getAppDir());
			}
		}
	}
	public function setAppDir($appDir) {
		$this->appDir = Z::realPath($appDir, true);
		$this->setPrimaryAppDir($this->appDir);
		return $this;
	}
	public function setPrimaryAppDir($primaryAppDir = '') {
		if (empty($this->primaryAppDir)) {
			$this->primaryAppDir = Z::realPath($primaryAppDir ?: $this->appDir, true);
		}
		return $this;
	}
	public function composer() {
		if (!isset($GLOBALS['__composer_autoload_files']) && file_exists($composer = ZLS_APP_PATH . '../vendor/autoload.php')) {
			Z::includeOnce($composer);
		}
		return $this;
	}
	/**
	 * @return Zls_Request
	 */
	public function getRequest() {
		if (!$this->request) {
			$this->request = new \Zls_Request_Default();
		}
		return $this->request;
	}
	public function setRequest(Zls_Request $request) {
		$this->request = $request;
		return $this;
	}
	public function addRouter($router) {
		if (is_string($router)) {
			$router = Z::factory($router, true);
		}
		array_unshift($this->routersContainer, $router);
		return $this;
	}
	public function getRouters() {
		return array_merge($this->routersContainer, [Z::factory('Zls_Router_PathInfo', true)]);
	}
	public function addLoggerWriter($loggerWriter) {
		$this->loggerWriters[] = $loggerWriter;
		return $this;
	}
	public function setClientIpConditions(array $source, array $check) {
		if ($source) {
			$this->clientIpConditions['source'] = $source;
		}
		if ($check) {
			$this->clientIpConditions['check'] = $check;
		}
		return $this;
	}
	public function getSeparationRouter($controller, $hmvcModule) {
		if (!$this->separationRouter) {
			return false;
		}
		static $router;
		if (!$router) {
			$router = new \Zls_SeparationRouter();
		}
		if ($hmvcModule) {
			$controller = $hmvcModule . '_' . $controller;
		}
		return $router->find(str_replace('Controller_', '', $controller), $hmvcModule);
	}
	public function getSwooleUuid($prefix = '') {
		if (Z::isSwoole()) {
			$uuid = \Swoole\Coroutine::getuid();
			// } elseif (!$haveToSwoole && $this->getUseMyid()) {
			//     $uuid = getmypid();
		} else {
			$uuid = 0;
		}
		return $prefix . $uuid;
	}
}
class Zls_Context_Default {
	private static $context = [];
	public static function set($id, $value) {
		self::$context[$id] = $value;
		return $value;
	}
	public static function get($id, $def = null) {
		return Z::arrayGet(self::$context, $id, $def, false);
	}
	public static function has($id) {
		return Z::arrayKeyExists($id, self::$context, false);
	}
}
class Zls_Logger_Dispatcher {
	private static $instance;
	private static $memReverse;
	public static function initialize() {
		if (empty(self::$instance)) {
			self::setMemReverse();
			self::$instance = new self();
			Z::isPluginMode() ? ini_set('display_errors', true) : ini_set('display_errors', false);
			set_exception_handler([self::$instance, 'handleError']);
			set_exception_handler([self::$instance, 'handleException']);
			register_shutdown_function([self::$instance, 'handleFatal']);
		}
		return self::$instance;
	}
	public static function setMemReverse() {
		self::$memReverse = str_repeat('x', Zls::getConfig()->getExceptionMemoryReserveSize());
	}
	/**
	 * @param Zls_Exception $exception
	 */
	final public function handleException($exception) {
		Z::throwIf(Z::config()->getSwooleUuid() != '0', $exception);
		if (is_subclass_of($exception, 'Zls_Exception')) {
			Zls_Logger_Dispatcher::dispatch($exception);
		} else {
			Zls_Logger_Dispatcher::dispatch(new \Zls_Exception_500($exception->getMessage(), $exception->getCode(), get_class($exception), $exception->getFile(), $exception->getLine()));
		}
	}
	/**
	 * 异常
	 *
	 * @param Zls_Exception $exception
	 * @param bool          $result
	 *
	 * @return string
	 */
	final static public function dispatch(Zls_Exception $exception, $result = false) {
		$error = '';
		$config = Z::config();
		ini_set('display_errors', true);
		$loggerWriters = $config->getLoggerWriters();
		$AppendError = '';
		try {
			foreach ($loggerWriters as $loggerWriter) {
				$loggerWriter->write($exception);
			}
		} catch (\Exception $e) {
			$AppendError = ' (log processing failed. ' . $e->getMessage() . ')';
		}
		$handle = $config->getExceptionHandle();
		if ($config->getShowError() || $handle) {
			if ($handle instanceof \Zls_Exception_Handle) {
				$error = $handle->handle($exception);
			} else {
				$error = $exception->render(null, true);
			}
		} elseif (Z::isCli() && !Z::isSwoole()) {
			$error = $exception->render();
		} else {
			$path = [
				$config->getAppDir() . $config->getViewsDirName() . '/Error/' . $exception->getErrorCode() . '.php',
				$config->getPrimaryAppDir() . $config->getViewsDirName() . $exception->getErrorCode() . '.php',
			];
			if (file_exists($file = $path[0]) || file_exists($file = $path[1])) {
				$error = Z::view()->loadRaw($file, [], true);
			}
		}
		$error = $error . $AppendError;
		if (!$result) {
			echo $error;
		}
		return $error;
	}
	final public function handleError($code, $message, $file, $line) {
		if (0 !== error_reporting()) {
			$throw = in_array($code, [E_WARNING], true);
			$exception = new \Zls_Exception_500($message, $code, 'General Error', $file, $line);
			Z::throwIf($throw || Z::config()->getSwooleUuid() != '0', $exception, $message, $code);
			Zls_Logger_Dispatcher::dispatch($exception);
		}
		return;
	}
	final public function handleFatal() {
		if (0 == error_reporting()) {
			return;
		}
		$lastError = error_get_last();
		$fatalError = [1, 256, 64, 16, 4, 4096];
		if (!Z::arrayKeyExists('type', $lastError) || !in_array($lastError['type'], $fatalError)) {
			return;
		}
		self::$memReverse = null;
		$exception = new Zls_Exception_500($lastError['message'], $lastError['type'], 'Fatal Error', $lastError['file'], $lastError['line']);
		Z::throwIf(Z::config()->getSwooleUuid() != '0', $exception);
		$error = Zls_Logger_Dispatcher::dispatch($exception, true);
		if (Z::isSwoole(true)) {
			$response = Z::di()->makeShared(SWOOLE_RESPONSE);
			$response->write($error);
			$response->end();
		} else {
			echo $error;
		}
		Z::eventEmit(ZLS_PREFIX . 'DEFER');
	}
}
class Zls_Maintain_Handle_Default implements Zls_Maintain_Handle {
	public function handle() {
		if (!Z::isCli()) {
			Z::header('Content-type: text/html;charset=utf-8');
		}
		$en = 'server is under maintenance';
		$zh = '服务器维护中';
		if (Z::isAjax()) {
			return Z::json(503, $zh, $en);
		} else {
			return "<center><h2>{$en}</h2><h3>{$zh}</h3>" . date('Y/m/d H:i:s e') . '</center>';
		}
	}
}
class Zls_Uri_Rewriter_Default implements Zls_Uri_Rewriter {
	public function rewrite($uri) {
		return $uri;
	}
}
class Zls_Exception_Handle_Default implements Zls_Exception_Handle {
	public function handle(\Zls_Exception $exception) {
		$exception->render();
	}
}
class Zls_Database_SlowQuery_Handle_Default implements Zls_Database_SlowQuery_Handle {
	public function handle($sql, $value, $explainString, $time, $trace) {
		$content = 'SQL : ' . $sql . PHP_EOL . 'value : ' . $value . PHP_EOL . 'explain : ' . $explainString . PHP_EOL . 'usingtime : ' . $time . 'ms' . PHP_EOL . 'time : ' . date('Y-m-d H:i:s');
		if ($trace) {
			$content = 'file : ' . $trace['file'] . PHP_EOL . 'line : ' . $trace['line'] . PHP_EOL . 'class : ' . $trace['class'] . PHP_EOL . 'function : ' . $trace['function'] . PHP_EOL . $content;
		}
		Z::log($content . PHP_EOL, 'slowQueryProduction');
	}
}
class Zls_Database_Index_Handle_Default implements Zls_Database_Index_Handle {
	public function handle($sql, $value, $explainString, $time, $trace) {
		$content = 'SQL : ' . $sql . PHP_EOL . 'value : ' . $value . PHP_EOL . 'explain : ' . $explainString . PHP_EOL . 'usingtime : ' . $time . 'ms' . PHP_EOL . 'time : ' . date('Y-m-d H:i:s');
		if ($trace) {
			$content = 'file : ' . $trace['file'] . PHP_EOL . 'line : ' . $trace['line'] . PHP_EOL . 'class : ' . $trace['class'] . PHP_EOL . 'function : ' . $trace['function'] . PHP_EOL . $content;
		}
		Z::log($content . PHP_EOL, 'indexProduction');
	}
}
class Zls_Cache_File implements Zls_Cache {
	private $_cacheDirPath;
	public function __construct($cacheDirPath = '') {
		$cacheDirPath = empty($cacheDirPath) ? Z::config()->getStorageDirPath() . 'cache/' : $cacheDirPath;
		$this->_cacheDirPath = Z::realPath($cacheDirPath) . '/';
	}
	public function clean() {
		return Z::rmdir($this->_cacheDirPath, false);
	}
	public function get($key) {
		if (empty($key)) {
			return null;
		}
		$_key = $this->_hashKey($key);
		$filePath = $this->_hashKeyPath($_key);
		if (file_exists($filePath)) {
			if ($userData = @file_get_contents($filePath)) {
				$userData = $this->unpack($userData);
			}
			if (!is_null($userData)) {
				return $userData;
			} else {
				$this->delete($key);
			}
		}
		return null;
	}
	private function _hashKey($key) {
		return md5($key) . md5(base64_encode($key));
	}
	private function _hashKeyPath($key) {
		return $this->_cacheDirPath . $key;
	}
	private function unpack($cacheData) {
		$cacheData = @unserialize($cacheData);
		if (
			is_array($cacheData) && Z::arrayKeyExists('userData', $cacheData)
			&& Z::arrayKeyExists(
				'expireTime',
				$cacheData
			)
		) {
			$expireTime = $cacheData['expireTime'];
			$userData = $cacheData['userData'];
			if (0 == $expireTime) {
				return $userData;
			}
			return $expireTime > time() ? $userData : null;
		} else {
			return null;
		}
	}
	public function delete($key) {
		if (empty($key)) {
			return false;
		}
		$key = $this->_hashKey($key);
		$filePath = $this->_hashKeyPath($key);
		if (file_exists($filePath)) {
			return @unlink($filePath);
		}
		return true;
	}
	public function set($key, $value, $cacheTime = 0) {
		if (empty($key)) {
			return false;
		}
		$key = $this->_hashKey($key);
		$filePath = Z::realPathMkdir($this->_hashKeyPath($key), false, true, false, false);
		$cacheData = $this->pack($value, $cacheTime);
		if (empty($cacheData)) {
			return false;
		}
		return Z::tap(Z::forceUmask(function () use ($filePath, $cacheData) {
			return file_put_contents($filePath, $cacheData, LOCK_EX);
		}), function () use ($filePath, $cacheTime) {
			@touch($filePath, time() + ($cacheTime > 0 ? $cacheTime : 31536000));
		});
	}
	private function pack($userData, $cacheTime) {
		$cacheTime = (int) $cacheTime;
		return @serialize(
			['userData' => $userData, 'expireTime' => (0 == $cacheTime ? 0 : time() + $cacheTime)]
		);
	}
	public function &instance($key = null, $isRead = true) {
		return $this;
	}
	public function reset() {
		return $this;
	}
}
class Zls_Trace {
	public static function instance() {
		static $instance;
		if (!$instance) {
			$instance = new self();
		}
		return $instance;
	}
	public function mysql($data, $type = 'mysql') {
		$content = '';
		foreach ($data as $key => $item) {
			$content = "{$key}:{$item}" . PHP_EOL . $content;
		}
		$this->output($content, $type);
	}
	public function output($content, $type, $debug = []) {
		$fn = function ($content) {
			$_content = '';
			if (is_array($content)) {
				foreach ($content as $key => $value) {
					try {
						$value = is_string($value) ? $value : var_export($value, true);
					} catch (\Exception $e) {
						$value = is_string($value) ? $value : print_r($value, true);
					}
					$_content .= $key . ' : ' . $value . PHP_EOL;
				}
			} else {
				$_content = print_r($content, true);
			}
			return $_content;
		};
		$debug = $fn($debug);
		$prefix = str_repeat('=', 25) . (new \DateTime())->format('Y-m-d H:i:s u') . str_repeat('=', 25);
		if (is_bool($content)) {
			$content = var_export($content, true);
		} elseif (!is_string($content)) {
			$content = $fn($content);
		}
		$content = $prefix . PHP_EOL . $debug . $content;
		$callBack = Z::config()->getTraceStatusCallBack();
		if (is_callable($callBack)) {
			$callBack($content, $type);
		} else {
			if (!file_exists($saveFile = $this->saveDirPath($type))) {
				$this->clear($saveFile);
			}
			Z::forceUmask(function () use ($saveFile, $content) {
				return @file_put_contents($saveFile, $content . PHP_EOL, LOCK_EX | FILE_APPEND);
			});
		}
	}
	public function saveDirPath($type) {
		return Z::realPathMkdir(Z::config()->getStorageDirPath() . $type . '/' . date('Y-m-d') . '.log', false, true, false, false);
	}
	public function clear($saveFile) {
		$logsMaxDay = Z::config()->getLogsMaxDay();
		$etime = time();
		$stime = $etime - ($logsMaxDay * 86400);
		$datearr = [];
		while ($stime <= $etime) {
			$datearr[] = date('Y-m-d', $etime);
			$etime = $etime - 3600 * 24;
		}
		$dir = pathinfo($saveFile, PATHINFO_DIRNAME);
		if ($dh = opendir($dir)) {
			while (false !== ($file = readdir($dh))) {
				if ('.' != $file && '..' != $file) {
					if (!in_array(pathinfo($file, PATHINFO_FILENAME), $datearr, true)) {
						@unlink($dir . '/' . $file);
					}
				}
			}
			closedir($dh);
		}
	}
	public function log(array $data, $type = 'trace') {
		$arg = '';
		foreach ($data['args'] as $key => $v) {
			$arg .= '------[arg_' . $key . ']------' . PHP_EOL . var_export($v, true) . PHP_EOL;
		}
		$debug = Z::debug(null, false, true, false);
		$this->output(
			vsprintf(
				"traceType : %s\ntime : %s\nruntime : %s\nmemory : %s\npath : %s\nline : %u\nargs : \n%s\ntrace : \n%s\n\n",
				['log', date('Y-m-d H:i:s'), $debug['runtime'] . 's', $debug['memory'], Z::safePath($data['file']), $data['line'], $arg, var_export(Z::arrayGet($data, 'trace', []), true)]
			),
			$type
		);
	}
}
