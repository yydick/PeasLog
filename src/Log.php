<?php

declare(strict_types=1);
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Spool\PeasLog;

use Spool\PeasLog\Exceptions\PedisLogException;

/**
 * Pedis日志类, 配置部分向SeasLog学习了
 * 功能用PHP原生实现,避免不支持安装扩展的公司
 * @author 大天使长
 */

/**
 * 记录一条公共日志
 * @method static alert(string $msg='', array $content=[], string $logger='default')
 * 记录一条公共日志
 * @method static critical(string $msg='', array $content=[], string $logger='default')
 * 记录一条公共日志
 * @method static error(string $msg='', array $content=[], string $logger='default')
 * 记录一条公共日志
 * @method static warning(string $msg='', array $content=[], string $logger='default')
 * 记录一条公共日志
 * @method static notice(string $msg='', array $content=[], string $logger='default')
 * 记录一条公共日志
 * @method static info(string $msg='', array $content=[], string $logger='default')
 * 记录一条公共日志
 * @method static debug(string $msg='', array $content=[], string $logger='default')
 */
class Log
{

    /**
     * @var LogConfig $logConfig 日志配置
     */
    private static $logConfig;
    private static $logFd;
    private static $logger = '';
    private static $buffer = [];
    private static $requestID = '';
    private static $level = [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7
    ];
    /**
     * 私有化构建函数, 禁用
     */
    private function __construct()
    {
    }

    public static function setConfig(LogConfig $config = null)
    {
        if ($config) {
            self::$logConfig = $config;
        } else if (!self::$logConfig) {
            self::$logConfig = new LogConfig();
        }
        if (!self::$requestID) {
            self::$requestID = uniqid('', true);
        }
    }

    public static function __callStatic(string $name, array $arguments): bool
    {
        if (!isset(self::$level[strtolower($name)])) {
            throw new PedisLogException('日志方法没找到', 8301);
        }
        $msg = $arguments[0] ?? '';
        $content = $arguments[1] ?? [];
        $logger = $arguments[2] ?? '';
        return self::log($name, $msg, $content, $logger);
    }

    public static function log(string $level, string $msg = '', array $content = [], string $logger = ''): bool
    {
        if (!isset(self::$level[strtolower($level)])) {
            throw new PedisLogException('日志方法没找到', 8301);
        }
        if (!self::$logConfig) {
            self::setConfig();
        }
        $numLevel = self::$level[$level];
        if ($numLevel > self::$logConfig->level) {
            return false;
        }
        $info = debug_backtrace();
        $callInfo = current($info);
        $time = date(self::$logConfig->defaultDatetimeFormat);
        $host = gethostname();
        $pid = posix_getpid();
        $d = gethostbyname($host);
        $uri = basename($callInfo['file']);
        $method = $callInfo['function'];
        $remoteIp = $content['remote-ip'] ?? '';
        $line = $callInfo['line'];
        $file = $callInfo['file'] . ':' . $line;
        $usage = memory_get_usage(true);
        $pusage = memory_get_peak_usage(true);
        $classInfo = $callInfo['class'];
        $tmpl = str_replace(
            [
                '%L', '%M', '%T', '%t', '%Q', '%H', '%P',
                '%D', '%R', '%m', '%l', '%F', '%U', '%u', '%C'
            ],
            [
                $level, $msg, $time, microtime(true), self::$requestID, $host, $pid,
                $d, $uri, $method, $remoteIp, $file, $usage, $pusage, $classInfo
            ],
            self::$logConfig->defaultTemplate
        );
        $len = strlen($tmpl);
        $wlen = self::writeLog($logger, $level, $tmpl, $len);
        return $len === $wlen;
    }

    public static function resetFd()
    {
        if (self::$logConfig->appender > 0 && self::$logFd) {
            fclose(self::$logFd);
        }
        self::$logFd = null;
        self::$logger = '';
    }

    public static function flushBuffer(): bool
    {
        return self::putBuffer() > 0 ? true : false;
    }

    public static function getBasePath(): string
    {
        return self::$logConfig->defaultBasepath;
    }

    public function getBuffer(): array
    {
        return self::$buffer;
    }

    public static function getBufferEnabled(): bool
    {
        return self::$logConfig->useBuffer ? true : false;
    }

    public static function getDatetimeFormat(): string
    {
        return self::$logConfig->defaultDatetimeFormat;
    }

    public static function getRequestID(): string
    {
        return self::$requestID;
    }

    public static function setBashPath(string $path): bool
    {
        self::$logConfig->defaultBasepath = $path;
        return true;
    }

    public static function setDatetimeFormat(string $format): bool
    {
        self::$logConfig->defaultDatetimeFormat = $format;
        return true;
    }

    public static function setLogger(string $logger): bool
    {
        self::$logConfig->defaultLogger = $logger;
        return true;
    }

    public static function setRequestID(string $requestID): bool
    {
        self::$requestID = $requestID;
        return true;
    }

    private static function getFd(string $logger = '', string $level = '')
    {
        if (self::$logConfig->appender < 0 || self::$logConfig->appender > 4) {
            throw new PedisLogException(8100, '日志介质设置错误，只能在0~3之间');
        }
        if (0 === self::$logConfig->appender) {
            self::$logFd = STDOUT;
            self::$logger = '';
        } elseif (1 === self::$logConfig->appender) {
            $fileLogger = self::getFileLogger($logger, $level);
            if (self::$logger != $fileLogger) {
                try {
                    self::$logFd = fopen($fileLogger, 'a');
                    self::$logger = $fileLogger;
                } catch (PedisLogException $exc) {
                    self::doException($exc);
                }
            }
        } elseif (2 < self::$logConfig->appender) {
            if (!self::$logFd) {
                self::$logFd = self::getSocketLogger();
            }
        }
    }

    private static function getFileLogger(string $logger = '', string $level = ''): string
    {
        $fileLogger = self::$logConfig->defaultBasepath;
        if (!is_dir($fileLogger)) {
            throw new PedisLogException(8101, '日志默认目录不可用');
        }
        if ($fileLogger && DS != substr($fileLogger, -1)) {
            $fileLogger .= DS;
        }
        if (self::$logConfig->distingFolder) {
            if (!is_dir($fileLogger . $logger)) {
                try {
                    mkdir($fileLogger . $logger, 0755, true);
                } catch (PedisLogException $exc) {
                    self::doException($exc);
                }
            }
            $fileLogger .= $logger . DS;
        } else {
            $fileLogger .= $logger;
        }
        if (self::$logConfig->distingType) {
            $fileLogger .= $level;
        }
        if (self::$logConfig->distingByHour) {
            $fileLogger .= date('YmdH');
        } else {
            $fileLogger .= date('Ymd');
        }
        if (self::$logConfig->defaultExt) {
            $fileLogger .= '.' . self::$logConfig->defaultExt;
        }
        return $fileLogger;
    }

    private static function doException(PedisLogException $exc)
    {
        if (self::$logConfig->throwException) {
            throw $exc;
        } else {
            echo $exc->getTraceAsString();
        }
    }

    private static function getSocketLogger()
    {
        $errno = NULL;
        $errstr = NULL;
        if (2 === self::$logConfig->appender) {
            $protocol = 'tcp://';
        } elseif (3 === self::$logConfig->appender) {
            $protocol = 'udp://';
        } else {
            return NULL;
        }
        return fsockopen(
            $protocol . self::$logConfig->remoteHost,
            self::$logConfig->remotePort,
            $errno,
            $errstr,
            self::$logConfig->remoteTimeout
        );
    }

    private static function writeLog(string $logger, string $level, string $msg, int $len = 0): int
    {
        self::getFd($logger, $level);
        $len = $len ?: strlen($msg);
        if (!self::$logConfig->useBuffer) {
            $wlen = fwrite(self::$logFd, $msg, $len);
            if (false == $wlen) {
                throw new PedisLogException(8201, '日志写入失败');
            }
        }
        $count = count(self::$buffer);
        if ($count + 1 < self::$logConfig->bufferSize) {
            $tmpl = [
                'msg' => $msg,
                'len' => $len,
            ];
            self::$buffer[] = $tmpl;
        }
        if ($count + 1 === self::$logConfig->bufferSize) {
            self::putBuffer();
        }
        return -1;
    }

    private static function putBuffer(): int
    {
        $len = 0;
        foreach (self::$buffer as $buffer) {
            try {
                $wlen = fwrite(self::$logFd, $buffer['msg'], $buffer['len']);
                $len += $wlen;
            } catch (PedisLogException $exc) {
                self::doException($exc);
            }
        }
        return $len;
    }
}
