<?php
/**
 * 助手函数
 *
 * @author mybsdc <mybsdc@gmail.com>
 * @date 2019/11/27
 * @time 9:40
 */

use Luolongfei\App\Env;

if (!function_exists('env')) {
    /**
     * 获取环境变量值
     *
     * @param $key
     * @param null $default
     *
     * @return array|bool|false|null|string
     */
    function env($key, $default = null)
    {
        Env::instance()->load();
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }
        if (($valueLength = strlen($value)) > 1 && $value[0] === '"' && $value[$valueLength - 1] === '"') { // 去除双引号
            return substr($value, 1, -1);
        }
        return $value;
    }
}