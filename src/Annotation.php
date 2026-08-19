<?php

declare(strict_types=1);

namespace Kingbes\Annotation;

/**
 * 通用注解类，数组键值使用 => 语法。
 */
#[\Attribute(\Attribute::TARGET_ALL | \Attribute::IS_REPEATABLE)]
class Annotation
{
    /**
     * 注解数据.
     */
    protected array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * 获取注解数据.
     */
    public function get(): array
    {
        return $this->data;
    }
}