<?php

/**
 * 一个简单的测试
 * @author yydick <yydick@sohu.com>
 * @category Spool
 * @package PeasLog
 */

namespace Spool\PeasLog\Test;

use PHPUnit\Framework\TestCase;
use Spool\PeasLog\LogConfig;
use Spool\PeasLog\CodeGenerator;

/**
 * @Author yydick yydick@sohu.com
 * @DateTime 2020-10-16
 */
class EmplTest extends TestCase
{
    /**
     * 简单测试
     */
    public function testEmpl(): bool
    {
        $logConfig = new LogConfig();
        $cg = new CodeGenerator();
        echo $cg->generator($logConfig);
        $this->assertTrue(true);
        return true;
    }
}
