<?php

namespace Zifan\AddressParser;

interface DataProviderInterface
{
    /**
     * 构建全国区划和城乡划分的数据（树形结构）
     * @return array|\ArrayAccess like [
     *     0 => [
     *         'name' => '北京',
     *         'children' => [
     *             [
     *                 'name' => '北京市',
     *                 'children' => [
     *                     ['name' => '朝阳区'],
     *                     ...
     *                 ]
     *             ]
     *         ]
     *     ],
     *     1 => [
     *         'name' => '浙江省',
     *         'children' => [...]
     *     ],
     *     ...
     * ]
     */
    public function toTree();
}