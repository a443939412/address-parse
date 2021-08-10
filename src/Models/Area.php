<?php

namespace Zifan\AddressParser\Models;

use Encore\Admin\Traits\ModelTree;
use Illuminate\Database\Eloquent\Model;

/**
 * Zifan\AddressParser\Models\Area
 *
 * @property int $id 索引ID
 * @property string $name 地区名称
 * @property int $parent_id 地区父ID
 * @property int $deep 地区深度，从1开始
 * @property-read \Illuminate\Database\Eloquent\Collection|Area[] $children
 * @property-read int|null $children_count
 * @property-read Area $parent
 * @method static \Illuminate\Database\Eloquent\Builder|Area whereDeep($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Area whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Area whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Area whereParentId($value)
 * @mixin \Eloquent
 */
class Area extends Model
{
    use ModelTree;

    public $timestamps = false;

    public function initializeModelTree()
    {
        $this->titleColumn = 'name';

        $this->orderColumn = 'id';
    }
}