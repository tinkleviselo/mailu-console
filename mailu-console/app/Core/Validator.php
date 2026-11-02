<?php
/**
 * Tiny rule based validator: "required|max:64|email|in:a,b|same:field".
 */

namespace App\Core;

final class Validator
{
    private $data;
    private $rules;
    private $errors = array();
    private $labels;

    public function __construct(array $data, array $rules, array $labels = array())
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->labels = $labels;
    }

    public static function make(array $data, array $rules, array $labels = array())
    {
        return new self($data, $rules, $labels);
    }

    public function fails()
    {
        return count($this->errors()) > 0;
    }

    public function errors()
    {
        $this->validate();
        return $this->errors;
    }

    public function validate()
    {
        if ($this->errors || $this->validated) {
            return $this->errors;
        }
        $this->validated = true;
        foreach ($this->rules as $field => $ruleString) {
            $value = array_key_exists($field, $this->data) ? $this->data[$field] : null;
            $label = isset($this->labels[$field]) ? $this->labels[$field] : $field;
            foreach (explode('|', (string) $ruleString) as $rule) {
                if ($rule === '' || $rule === 'nullable') {
                    continue;
                }
                $param = null;
                if (strpos($rule, ':') !== false) {
                    list($rule, $param) = explode(':', $rule, 2);
                }
                $this->apply($field, $label, $value, $rule, $param);
            }
        }
        return $this->errors;
    }

    private $validated = false;

    private function fail($field, $message)
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    private function apply($field, $label, $value, $rule, $param)
    {
        $isEmpty = ($value === null || $value === '' || (is_array($value) && count($value) === 0));
        switch ($rule) {
            case 'required':
                if ($isEmpty) {
                    $this->fail($field, $label . ' 不能为空');
                }
                return;
            case 'email':
                if (!$isEmpty && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->fail($field, $label . ' 不是合法的邮箱地址');
                }
                return;
            case 'domain':
                if (!$isEmpty && !preg_match('/^(?=.{1,253}$)([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/', (string) $value)) {
                    $this->fail($field, $label . ' 不是合法的域名');
                }
                return;
            case 'url':
                if (!$isEmpty && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $this->fail($field, $label . ' 不是合法的 URL');
                }
                return;
            case 'numeric':
                if (!$isEmpty && !is_numeric($value)) {
                    $this->fail($field, $label . ' 必须是数字');
                }
                return;
            case 'integer':
                if (!$isEmpty && !preg_match('/^-?\d+$/', (string) $value)) {
                    $this->fail($field, $label . ' 必须是整数');
                }
                return;
            case 'min':
                if (!$isEmpty && $this->length($value) < (int) $param) {
                    $this->fail($field, $label . ' 至少需要 ' . (int) $param . ' 个字符');
                }
                return;
            case 'max':
                if (!$isEmpty && $this->length($value) > (int) $param) {
                    $this->fail($field, $label . ' 最多 ' . (int) $param . ' 个字符');
                }
                return;
            case 'in':
                $allowed = explode(',', (string) $param);
                if (!$isEmpty && !in_array((string) $value, $allowed, true)) {
                    $this->fail($field, $label . ' 取值不合法');
                }
                return;
            case 'same':
                $other = array_key_exists($param, $this->data) ? $this->data[$param] : null;
                if ((string) $value !== (string) $other) {
                    $this->fail($field, $label . ' 与对比字段不一致');
                }
                return;
            case 'regex':
                if (!$isEmpty && !preg_match($param, (string) $value)) {
                    $this->fail($field, $label . ' 格式不正确');
                }
                return;
            case 'ip':
                if (!$isEmpty && !filter_var($value, FILTER_VALIDATE_IP)) {
                    $this->fail($field, $label . ' 不是合法的 IP');
                }
                return;
            case 'array':
                if (!$isEmpty && !is_array($value)) {
                    $this->fail($field, $label . ' 必须是列表');
                }
                return;
            default:
                return;
        }
    }

    private function length($value)
    {
        if (is_array($value)) {
            return count($value);
        }
        $value = (string) $value;
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}