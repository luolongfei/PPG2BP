<?php
/**
 * PHP命令行颜色
 *
 * 目前多层标签嵌套还存在问题，当多层嵌套时，内嵌标签后面的文字样式会丢失，待原作者修正
 *
 * @author mybsdc <mybsdc@gmail.com>
 * @date 2019/11/29
 * @time 10:52
 */

namespace Luolongfei\App;

use Colors\Color;

class PhpColor
{
    /**
     * @var Color
     */
    protected static $colorInstance;

    /**
     * @return Color
     */
    public static function getColorInstance()
    {
        if (!self::$colorInstance instanceof Color) {
            self::$colorInstance = new Color();

            // Create my own style
            self::$colorInstance->setUserStyles([
//                '自定义标签' => 'red',
            ]);
        }

        return self::$colorInstance;
    }
}