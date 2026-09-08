<?php

namespace App\Dao;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * 抽象数据访问对象（DAO）基类。
 *
 * 需要通用 CRUD 的 DAO 继承此类，并通过 model() 返回对应模型类名。
 * 聚合多个模型的业务 DAO 直接封装专用查询。
 *
 * 设计原则（参考 novel-api/BaseDao）：
 *   - DAO 内部严禁引用 Service 或 Controller
 *   - 方法返回强类型数据或抛异常，不返回混合结果
 *   - 复杂查询组合在具体 DAO 中实现
 *
 * @template T of EloquentModel
 */
abstract class BaseDao
{
    /** @var class-string<T> 缓存的模型类名 */
    protected string $modelClass;

    /**
     * 返回当前 DAO 关联的 Eloquent 模型类名。
     *
     * @return class-string<T>
     */
    abstract protected function model(): string;

    /**
     * 按主键或 where 条件查找单条记录。
     *
     * @param  int|array  $pkOrWhere  主键 ID（int）或 where 条件（数组）
     * @param  array      $fields     需要选择的列，默认全选
     * @return T|null                 命中返回模型实例，否则 null
     */
    public function find(int|array $pkOrWhere, array $fields = ['*']): ?EloquentModel
    {
        $query = $this->query();
        if (is_array($pkOrWhere)) {
            $query->where($pkOrWhere);
        } else {
            $query->where($this->pk(), $pkOrWhere);
        }

        return $query->first($fields);
    }

    /**
     * 按 where 条件获取多条记录。
     *
     * @param  array                                  $where   字段 => 值（数组值用 whereIn）
     * @param  array                                  $fields  选择的列
     * @param  array<string, 'asc'|'desc'>            $order   排序规则
     * @return Collection<int, T>                              命中集合
     */
    public function getAll(
        array $where = [],
        array $fields = ['*'],
        array $order = [],
    ): Collection {
        $query = $this->query();
        foreach ($where as $key => $value) {
            if (is_array($value)) {
                $query->whereIn($key, $value);
            } else {
                $query->where($key, $value);
            }
        }
        foreach ($order as $field => $direction) {
            $query->orderBy($field, strtolower($direction) === 'desc' ? 'desc' : 'asc');
        }

        return $query
            ->select($fields)
            ->get();
    }

    /**
     * 按 where 条件分页查询。
     *
     * @param  array                                  $where    字段 => 值
     * @param  int                                    $perPage  每页条数
     * @param  int                                    $page     1-based 页码
     * @param  array<string, 'asc'|'desc'>            $order    排序规则
     * @param  array                                  $fields   选择的列
     * @return LengthAwarePaginator<T>                          分页器
     */
    public function paginate(
        array $where = [],
        int $perPage = 20,
        int $page = 1,
        array $order = [],
        array $fields = ['*'],
    ): LengthAwarePaginator {
        $query = $this->query();
        foreach ($where as $key => $value) {
            if (is_array($value)) {
                $query->whereIn($key, $value);
            } else {
                $query->where($key, $value);
            }
        }
        foreach ($order as $field => $direction) {
            $query->orderBy($field, strtolower($direction) === 'desc' ? 'desc' : 'asc');
        }

        return $query
            ->select($fields)
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 创建一条新记录。
     *
     * @param  array  $data  模型可填充属性
     * @return T             已写入数据库的模型实例（含主键 ID）
     */
    public function create(array $data): EloquentModel
    {
        $model = $this->newModel($data);
        $model->save();

        return $model;
    }

    /**
     * 创建一条新记录并返回主键值。
     *
     * @param  array  $data  模型可填充属性
     * @return int           新记录的主键 ID；保存失败返回 0
     */
    public function insertGetId(array $data): int
    {
        $model = $this->newModel($data);
        $saved = $model->save();

        return $saved ? (int) $model->{$this->pk()} : 0;
    }

    /**
     * 按 where 条件更新记录。
     *
     * @param  array  $where  更新条件
     * @param  array  $data   待更新字段
     * @return int            受影响行数
     */
    public function updateWhere(array $where, array $data): int
    {
        return $this
            ->query()
            ->where($where)
            ->update($data);
    }

    /**
     * 按属性匹配存在则更新，否则插入。
     *
     * @param  array  $attributes  匹配条件
     * @param  array  $values      插入/更新字段
     * @return array{0: bool, 1: T} 是否新建 + 模型实例
     */
    public function updateOrCreate(array $attributes, array $values): array
    {
        $model = $this
            ->query()
            ->where($attributes)
            ->first();
        if ($model) {
            $model
                ->fill($values)
                ->save();

            return [false, $model];
        }
        $model = $this->newModel(array_merge($attributes, $values));
        $model->save();

        return [true, $model];
    }

    /**
     * 按 where 条件删除记录（软删除模型走 SoftDeletes 行为）。
     *
     * @param  array  $where  删除条件
     * @return int            受影响行数
     */
    public function deleteWhere(array $where): int
    {
        return $this
            ->query()
            ->where($where)
            ->delete();
    }

    /**
     * 判断指定 where 条件下是否存在记录。
     *
     * @param  array  $where  查询条件
     * @return bool           存在返回 true
     */
    public function exists(array $where): bool
    {
        return $this
            ->query()
            ->where($where)
            ->exists();
    }

    /**
     * 按 where 条件统计记录数。
     *
     * @param  array  $where  查询条件
     * @return int            命中数量
     */
    public function count(array $where = []): int
    {
        return $this
            ->query()
            ->where($where)
            ->count();
    }

    /**
     * 按 where 条件对指定字段求和。
     *
     * @param  string  $field  字段名
     * @param  array   $where  查询条件
     * @return float           求和结果
     */
    public function sum(string $field, array $where = []): float
    {
        return (float) $this
            ->query()
            ->where($where)
            ->sum($field);
    }

    /**
     * 按主键批量更新不同列的值。
     *
     * @param  string     $tableName  目标表名（用于直接 SQL）
     * @param  array      $records    待更新记录数组，每条必须包含 'id' 字段
     * @return int                    受影响行数
     */
    public function updateBatch(string $tableName, array $records): int
    {
        if (empty($records)) {
            return 0;
        }
        $first = reset($records);
        $primaryKey = array_key_first($first);
        $sets = [];
        $bindings = [];
        foreach (array_keys(array_diff_key($first, [$primaryKey => $first[$primaryKey]])) as $column) {
            $setSql = "`{$column}` = CASE ";
            foreach ($records as $record) {
                $setSql .= "WHEN `{$primaryKey}` = ? THEN ? ";
                $bindings[] = $record[$primaryKey];
                $bindings[] = $record[$column];
            }
            $setSql .= "ELSE `{$column}` END ";
            $sets[] = $setSql;
        }
        $whereIn = implode(',', array_fill(0, count($records), '?'));
        $sql = "UPDATE `{$tableName}` SET " . implode(', ', $sets) . " WHERE `{$primaryKey}` IN ({$whereIn})";

        return \DB::affectingStatement($sql, [...$bindings, ...array_column($records, $primaryKey)]);
    }

    /* ─── Protected helpers ─────────────────────────────── */
    /**
     * 获取模型查询构造器。
     *
     * @return \Illuminate\Database\Eloquent\Builder<T>
     */
    protected function query(): Builder
    {
        return $this->model()::query();
    }

    /**
     * 用指定属性实例化一个尚未保存的模型。
     *
     * @param  array  $attributes  初始属性
     * @return T                   模型实例
     */
    protected function newModel(array $attributes = []): EloquentModel
    {
        $class = $this->model();

        return new $class($attributes);
    }

    /**
     * 获取当前模型主键列名（自动从表中读出）。
     *
     * @return string  主键列名（一般是 'id'）
     */
    protected function pk(): string
    {
        return $this->model()::first()->getKeyName();
    }
}
