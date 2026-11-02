<?php
/**
 * Plain-PHP view renderer with a shared layout.
 */

namespace App\Core;

final class View
{
    private static $shared = array();

    public static function share($key, $value)
    {
        self::$shared[$key] = $value;
    }

    public static function shared()
    {
        return self::$shared;
    }

    public function render($view, array $data = array(), $layout = 'layout')
    {
        $file = VIEW_PATH . '/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('视图不存在: ' . $view);
        }
        $vars = array_merge(self::$shared, $data);
        extract($vars, EXTR_SKIP);
        ob_start();
        include $file;
        $content = ob_get_clean();
        if ($layout === null) {
            return $content;
        }
        $layoutFile = VIEW_PATH . '/' . str_replace('.', '/', $layout) . '.php';
        if (!is_file($layoutFile)) {
            return $content;
        }
        ob_start();
        include $layoutFile;
        return ob_get_clean();
    }

    public static function exists($view)
    {
        return is_file(VIEW_PATH . '/' . str_replace('.', '/', $view) . '.php');
    }
}