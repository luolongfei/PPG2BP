<?php
/**
 * 助手函数
 *
 * @author mybsdc <mybsdc@gmail.com>
 * @date 2019/11/27
 * @time 9:40
 */

use Luolongfei\App\Env;
use Luolongfei\App\PhpColor;

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

if (!function_exists('system_log')) {
    /**
     * 写日志
     *
     * @param $content
     * @param array $response
     * @param string $fileName
     * @description 受支持的着色标签
     * 'reset', 'bold', 'dark', 'italic', 'underline', 'blink', 'reverse', 'concealed', 'default', 'black', 'red',
     * 'green', 'yellow', 'blue', 'magenta', 'cyan', 'light_gray', 'dark_gray', 'light_red', 'light_green',
     * 'light_yellow', 'light_blue', 'light_magenta', 'light_cyan', 'white', 'bg_default', 'bg_black', 'bg_red',
     * 'bg_green', 'bg_yellow', 'bg_blue', 'bg_magenta', 'bg_cyan', 'bg_light_gray', 'bg_dark_gray', 'bg_light_red',
     * 'bg_light_green','bg_light_yellow', 'bg_light_blue', 'bg_light_magenta', 'bg_light_cyan', 'bg_white'
     */
    function system_log($content, array $response = [], $fileName = '')
    {
        try {
            $path = sprintf('%s/logs/%s/', ROOT_PATH, date('Y-m'));
            $file = $path . ($fileName ?: date('d')) . '.log';

            if (!is_dir($path)) {
                mkdir($path, 0777, true);
                chmod($path, 0777);
            }

            $handle = fopen($file, 'a'); // 追加而非覆盖

            if (!filesize($file)) {
                chmod($file, 0666);
            }

            $msg = sprintf(
                "[%s] %s %s\n",
                date('Y-m-d H:i:s'),
                is_string($content) ? $content : json_encode($content),
                $response ? json_encode($response, JSON_UNESCAPED_UNICODE) : '');

            // 尝试为消息着色
            $c = PhpColor::getColorInstance();
            echo $c($msg)->colorize();

            // 干掉着色标签
            $msg = strip_tags($msg);

            fwrite($handle, $msg);
            fclose($handle);

            flush();
        } catch (\Exception $e) {
            // do nothing
        }
    }
}

if (!function_exists('error_system_log')) {
    /**
     * 错误日志
     *
     * @param $content
     * @param array $response
     * @param string $fileName
     */
    function error_system_log($content, array $response = [], $fileName = '')
    {
        $content = sprintf('<bg_light_red><white>ERROR</white></bg_light_red> <light_red>%s</light_red>', $content);
        system_log($content, $response, $fileName);
    }
}

if (!function_exists('notice_system_log')) {
    /**
     * 提示日志
     *
     * @param $content
     * @param array $response
     * @param string $fileName
     */
    function notice_system_log($content, array $response = [], $fileName = '')
    {
        $content = sprintf('<bg_light_blue><white>NOTICE</white></bg_light_blue> <light_blue>%s</light_blue>', $content);
        system_log($content, $response, $fileName);
    }
}

if (!function_exists('warning_system_log')) {
    /**
     * 提示日志
     *
     * @param $content
     * @param array $response
     * @param string $fileName
     */
    function warning_system_log($content, array $response = [], $fileName = '')
    {
        $content = sprintf('<bg_yellow><white>WARNING</white></bg_yellow> <light_yellow>%s</light_yellow>', $content);
        system_log($content, $response, $fileName);
    }
}

if (!function_exists('info_system_log')) {
    /**
     * 提示日志
     *
     * @param $content
     * @param array $response
     * @param string $fileName
     */
    function info_system_log($content, array $response = [], $fileName = '')
    {
        $content = sprintf('<bg_light_green><white>INFO</white></bg_light_green> <light_green>%s</light_green>', $content);
        system_log($content, $response, $fileName);
    }
}